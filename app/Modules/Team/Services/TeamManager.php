<?php

declare(strict_types=1);

namespace App\Modules\Team\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Invitation;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\InvitationService;
use App\Modules\Team\Exceptions\TeamRefused;
use App\Modules\Team\Notifications\MemberAccessNotification;
use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Équipe et accès de l'établissement (Réglages > Équipe et accès, docs/34) : liste, invitation, modification des permissions, suspension,
 * réactivation, retrait. Tout s'exécute dans le contexte de l'organisation (RLS) ; chaque règle est vérifiée ici, côté serveur.
 *
 * Règles de sécurité :
 *  - le propriétaire est intouchable (ni modifié, ni suspendu, ni retiré) ;
 *  - on ne modifie jamais son propre accès, ni celui d'une personne qui peut gérer l'équipe sauf si l'on est propriétaire ;
 *  - on n'accorde que ce que l'on possède soi-même, et les capacités sensibles (équipe, facturation) seulement si l'on est propriétaire ;
 *  - tout changement est consigné (qui, quoi, quand, valeurs avant et après) et la personne concernée est prévenue par e-mail.
 */
final class TeamManager
{
    public function __construct(private readonly CurrentTenant $current, private readonly InvitationService $invitations) {}

    // ---- Lecture -------------------------------------------------------------------------------------------------------------------------

    /**
     * Toutes les personnes de l'équipe : membres (actifs, suspendus, retirés) et invitations en attente (statut « invité »).
     *
     * @return list<array<string, mixed>>
     */
    public function members(): array
    {
        $organization = $this->organization();
        $financial = (bool) $organization->reports_financial_managers;
        $self = (string) auth()->id();
        $practitioners = Practitioner::query()->whereNotNull('user_id')->get()->keyBy('user_id');
        $upcoming = DB::table('bookings')->where('starts_at', '>', now())->whereIn('status', ['pending', 'confirmed'])->selectRaw('practitioner_id, count(*) as n')->groupBy('practitioner_id')->pluck('n', 'practitioner_id');
        $rows = [];

        $members = OrgMember::query()->join('users', 'users.id', '=', 'org_members.user_id')
            ->select('org_members.*', 'users.email as user_email', 'users.last_login_at', 'users.phone_e164 as user_phone')->get();

        foreach ($members as $member) {
            $practitioner = $practitioners[$member->user_id] ?? null;
            $effective = Capabilities::effective($member->role, $member->permissions, $financial);

            $rows[] = [
                'id' => (string) $member->id,
                'kind' => 'member',
                'name' => $this->nameOf($member, $practitioner),
                'email' => (string) $member->getAttribute('user_email'),
                'phone' => $member->phone ?? $member->getAttribute('user_phone'),
                'role' => $this->roleLabel($member->role),
                'base_role' => $member->role,
                'preset' => $member->preset,
                'status' => $this->statusOf($member->status),
                'is_owner' => $member->role === OrgMember::ROLE_OWNER,
                'is_self' => (string) $member->user_id === $self,
                'practitioner_id' => $practitioner?->id,
                'photo_version' => $practitioner?->photoVersion(),
                'last_login_at' => $member->getAttribute('last_login_at') === null ? null : Carbon::parse($member->getAttribute('last_login_at'))->toIso8601String(),
                'created_at' => Carbon::parse($member->created_at)->toIso8601String(),
                'invited_at' => $member->invited_at === null ? null : Carbon::parse($member->invited_at)->toIso8601String(),
                'capabilities' => $effective,
                'level' => $this->level($effective),
                'notes' => $member->notes,
                'upcoming_appointments' => $practitioner === null ? 0 : (int) ($upcoming[$practitioner->id] ?? 0),
                'editable' => $this->canManage($member),
            ];
        }

        $pending = Invitation::query()->whereNull('used_at')->whereNull('revoked_at')->orderByDesc('created_at')->get();

        foreach ($pending as $invitation) {
            $effective = Capabilities::effective($invitation->role, $invitation->permissions, $financial);
            $rows[] = [
                'id' => (string) $invitation->id,
                'kind' => 'invitation',
                'name' => (string) ($invitation->name ?? $invitation->email),
                'email' => (string) $invitation->email,
                'phone' => $invitation->phone,
                'role' => $this->roleLabel($invitation->role),
                'base_role' => $invitation->role,
                'preset' => $invitation->preset,
                'status' => $invitation->expires_at->isPast() ? 'expired' : 'invited',
                'is_owner' => false,
                'is_self' => false,
                'practitioner_id' => $invitation->practitioner_id,
                'photo_version' => null,
                'last_login_at' => null,
                'created_at' => $invitation->created_at?->toIso8601String(),
                'invited_at' => $invitation->created_at?->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'capabilities' => $effective,
                'level' => $this->level($effective),
                'notes' => $invitation->notes,
                'editable' => $this->current->can('settings.team'),
            ];
        }

        return $rows;
    }

    /**
     * Combien de rendez-vous à venir sont confiés à un membre (pour expliquer ce que devient son planning au retrait).
     */
    public function upcomingAppointments(OrgMember $member): int
    {
        $practitionerId = Practitioner::query()->where('user_id', $member->user_id)->value('id');

        return $practitionerId === null ? 0 : (int) DB::table('bookings')->where('practitioner_id', $practitionerId)->where('starts_at', '>', now())->whereIn('status', ['pending', 'confirmed'])->count();
    }

    // ---- Invitation ----------------------------------------------------------------------------------------------------------------------

    /**
     * Invite un praticien ou un assistant avec ses permissions. `$capabilities` est la liste EXACTE de ce qu'il pourra faire.
     *
     * @param  array{name: string, email: string, role: string, phone?: string|null, notes?: string|null, preset?: string|null, capabilities: list<string>}  $data
     * @return array{invitation: Invitation, over_limit: bool}
     *
     * @throws TeamRefused
     */
    public function invite(array $data): array
    {
        $this->requireManager();
        $organization = $this->organization();
        $role = $data['role'];

        if (! in_array($role, Capabilities::INVITABLE_ROLES, true)) {
            throw TeamRefused::because('role_invalid');
        }

        $email = mb_strtolower(trim($data['email']));
        $this->assertFreeEmail($email);

        $financial = (bool) $organization->reports_financial_managers;
        $wanted = array_values(array_intersect(Capabilities::all(), $data['capabilities']));
        $this->assertGrantable($wanted, []);
        $overrides = Capabilities::overridesFor($role, $wanted, $financial);
        $this->assertSafeConfiguration($role, $wanted);

        $result = $this->invitations->inviteMember($organization, [
            'name' => trim($data['name']), 'email' => $email, 'role' => $role, 'phone' => $this->clean($data['phone'] ?? null, 30),
            'preset' => $this->preset($data['preset'] ?? null, $role, $overrides), 'permissions' => $overrides, 'notes' => $this->clean($data['notes'] ?? null, 1000),
        ], (string) auth()->id());

        Audit::record('team.invited', 'user', actorId: (string) auth()->id(), orgId: $organization->id, entityType: 'invitations', entityId: $result['invitation']->id, diff: [
            'email' => $email, 'name' => trim($data['name']), 'role' => $role, 'preset' => $result['invitation']->preset, 'granted' => $wanted,
        ]);

        return ['invitation' => $result['invitation'], 'over_limit' => $result['over_limit']];
    }

    /**
     * @throws TeamRefused
     */
    public function resendInvitation(string $invitationId): void
    {
        $this->requireManager();
        $invitation = $this->pending($invitationId);
        $this->invitations->resendInvitation($this->organization(), $invitation, (string) auth()->id());
    }

    /**
     * @throws TeamRefused
     */
    public function cancelInvitation(string $invitationId): void
    {
        $this->requireManager();
        $this->invitations->cancel($this->organization(), $this->pending($invitationId), (string) auth()->id());
    }

    // ---- Modification des accès ----------------------------------------------------------------------------------------------------------

    /**
     * Change le rôle de base et les capacités d'un membre ou d'une invitation en attente. `$acknowledged` : la personne qui enregistre a lu
     * l'avertissement d'une configuration qui retire des accès essentiels.
     *
     * @param  array{role?: string|null, preset?: string|null, capabilities: list<string>, name?: string|null, phone?: string|null, notes?: string|null}  $data
     * @return array{added: list<string>, removed: list<string>, role: array{0: string, 1: string}|null}
     *
     * @throws TeamRefused
     */
    public function updateAccess(string $id, array $data, bool $acknowledged = false): array
    {
        $this->requireManager();
        $organization = $this->organization();
        $financial = (bool) $organization->reports_financial_managers;
        $invitation = Invitation::query()->whereNull('used_at')->whereNull('revoked_at')->find($id);

        if ($invitation !== null) {
            return $this->updateInvitation($invitation, $data, $financial, $acknowledged);
        }

        $member = $this->member($id);
        $this->assertManageable($member);

        $oldRole = $member->role;
        $newRole = $data['role'] ?? $oldRole;

        if (! in_array($newRole, Capabilities::INVITABLE_ROLES, true)) {
            throw TeamRefused::because('role_invalid');
        }

        $oldOverrides = $member->permissions;
        $before = Capabilities::effective($oldRole, $oldOverrides, $financial);
        $wanted = array_values(array_intersect(Capabilities::all(), $data['capabilities']));
        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));
        $this->assertGrantable($added, $before);
        $this->assertSafeConfiguration($newRole, $wanted, $acknowledged);

        // Protéger l'accès à l'équipe et à la facturation : seul le propriétaire en retire à quelqu'un qui en a déjà.
        if (array_intersect($removed, Capabilities::PROTECTED) !== [] && $this->current->role() !== OrgMember::ROLE_OWNER) {
            throw TeamRefused::because('protected_change');
        }

        $overrides = Capabilities::overridesFor($newRole, $wanted, $financial);

        DB::transaction(function () use ($member, $newRole, $overrides, $data, $oldRole, $oldOverrides, $added, $removed, $organization): void {
            $member->forceFill([
                'role' => $newRole,
                'permissions' => $overrides === [] ? null : $overrides,
                'preset' => $this->preset($data['preset'] ?? null, $newRole, $overrides),
                'name' => array_key_exists('name', $data) ? ($this->clean($data['name'], 120) ?? $member->name) : $member->name,
                'phone' => array_key_exists('phone', $data) ? $this->clean($data['phone'], 30) : $member->phone,
                'notes' => array_key_exists('notes', $data) ? $this->clean($data['notes'], 1000) : $member->notes,
            ])->save();

            Audit::record('team.access_updated', 'user', actorId: (string) auth()->id(), orgId: $organization->id, entityType: 'org_members', entityId: $member->id, diff: [
                'role' => [$oldRole, $newRole], 'added' => $added, 'removed' => $removed, 'permissions' => [$oldOverrides, $overrides === [] ? null : $overrides],
            ]);
        });

        if ($added !== [] || $removed !== [] || $oldRole !== $newRole) {
            $this->notify($member, 'access_changed');
        }

        return ['added' => $added, 'removed' => $removed, 'role' => $oldRole === $newRole ? null : [$oldRole, $newRole]];
    }

    // ---- Statut --------------------------------------------------------------------------------------------------------------------------

    /**
     * @throws TeamRefused
     */
    public function suspend(string $id, ?string $reason = null): void
    {
        $this->requireManager();
        $member = $this->member($id);
        $this->assertManageable($member);

        if ($member->status !== 'active') {
            throw TeamRefused::because('not_active');
        }

        $this->transition($member, 'disabled', 'team.suspended', ['suspended_at' => now()], $reason);
        $this->notify($member, 'suspended');
    }

    /**
     * Réactive un membre suspendu ou retiré : il retrouve les mêmes accès qu'avant.
     *
     * @throws TeamRefused
     */
    public function reactivate(string $id): void
    {
        $this->requireManager();
        $member = $this->member($id);
        $this->assertManageable($member);

        if (! in_array($member->status, ['disabled', 'removed'], true)) {
            throw TeamRefused::because('not_suspended');
        }

        $this->transition($member, 'active', 'team.reactivated', ['suspended_at' => null, 'removed_at' => null], null);
        $this->notify($member, 'reactivated');
    }

    /**
     * Retire l'accès : la personne n'entre plus, son historique (rendez-vous, notes, journal) reste attribué. Ses rendez-vous à venir restent sur
     * sa fiche praticien, à réaffecter depuis le planning si besoin.
     *
     * @throws TeamRefused
     */
    public function remove(string $id, ?string $reason = null): void
    {
        $this->requireManager();
        $member = $this->member($id);
        $this->assertManageable($member);

        if ($member->status === 'removed') {
            throw TeamRefused::because('already_removed');
        }

        $this->transition($member, 'removed', 'team.removed', ['removed_at' => now()], $reason, ['upcoming_appointments' => $this->upcomingAppointments($member)]);
        $this->notify($member, 'removed');
    }

    /**
     * Envoie à la personne un lien pour choisir un nouveau mot de passe (son mot de passe actuel n'est jamais vu ni modifié ici).
     *
     * @throws TeamRefused
     */
    public function sendPasswordReset(string $id): void
    {
        $this->requireManager();
        $member = $this->member($id);
        $this->assertManageable($member);

        if ($member->status !== 'active') {
            throw TeamRefused::because('not_active');
        }

        $user = User::query()->findOrFail($member->user_id);
        Password::broker()->sendResetLink(['email' => $user->email]);
        Audit::record('team.password_reset_sent', 'user', actorId: (string) auth()->id(), orgId: $this->organization()->id, entityType: 'org_members', entityId: $member->id, diff: ['email' => $user->email]);
    }

    // ---- Règles --------------------------------------------------------------------------------------------------------------------------

    /**
     * Avertissements d'une configuration qui retire des accès essentiels : sans tableau de bord, ou un praticien sans calendrier ni rien d'autre.
     *
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public function warnings(string $role, array $capabilities): array
    {
        $warnings = [];

        if ($capabilities === []) {
            return ['nothing'];
        }

        if (! in_array('dashboard.view', $capabilities, true)) {
            $warnings[] = 'no_dashboard';
        }

        if ($role === OrgMember::ROLE_PRACTITIONER && ! in_array('calendar.view', $capabilities, true)) {
            $warnings[] = 'no_calendar';
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $capabilities
     *
     * @throws TeamRefused
     */
    private function assertSafeConfiguration(string $role, array $capabilities, bool $acknowledged = false): void
    {
        if ($this->warnings($role, $capabilities) !== [] && ! $acknowledged) {
            throw TeamRefused::because('essential_access');
        }
    }

    /**
     * On n'accorde que ce que l'on a soi-même, et les capacités sensibles seulement en tant que propriétaire.
     *
     * @param  list<string>  $added
     * @param  list<string>  $alreadyHad
     *
     * @throws TeamRefused
     */
    private function assertGrantable(array $added, array $alreadyHad): void
    {
        $isOwner = $this->current->role() === OrgMember::ROLE_OWNER;

        foreach ($added as $capability) {
            if (in_array($capability, $alreadyHad, true)) {
                continue;
            }

            if (in_array($capability, Capabilities::PROTECTED, true) && ! $isOwner) {
                throw TeamRefused::because('protected_grant');
            }

            if (! $this->current->can($capability)) {
                throw TeamRefused::because('cannot_grant');
            }
        }
    }

    /**
     * @throws TeamRefused
     */
    private function requireManager(): void
    {
        if (! $this->current->can('settings.team')) {
            throw TeamRefused::because('forbidden');
        }
    }

    /** Peut-on agir sur ce membre ? Jamais le propriétaire, jamais soi-même, et un gestionnaire d'équipe n'agit pas sur un autre. */
    private function canManage(OrgMember $member): bool
    {
        if ($member->role === OrgMember::ROLE_OWNER || (string) $member->user_id === (string) auth()->id() || ! $this->current->can('settings.team')) {
            return false;
        }

        if ($this->current->role() === OrgMember::ROLE_OWNER) {
            return true;
        }

        $theirs = Capabilities::effective($member->role, $member->permissions, (bool) $this->organization()->reports_financial_managers);

        return ! in_array('settings.team', $theirs, true);
    }

    /**
     * @throws TeamRefused
     */
    private function assertManageable(OrgMember $member): void
    {
        if ($member->role === OrgMember::ROLE_OWNER) {
            throw TeamRefused::because('owner_protected');
        }

        if ((string) $member->user_id === (string) auth()->id()) {
            throw TeamRefused::because('self');
        }

        if (! $this->canManage($member)) {
            throw TeamRefused::because('peer_manager');
        }
    }

    /**
     * Une adresse déjà dans l'équipe, ou invitée, est signalée précisément ; une adresse qui a un compte ailleurs reçoit un message neutre
     * (on ne révèle pas qu'un compte existe chez un autre établissement).
     *
     * @throws TeamRefused
     */
    private function assertFreeEmail(string $email): void
    {
        $inTeam = OrgMember::query()->join('users', 'users.id', '=', 'org_members.user_id')->where('users.email', $email)->value('org_members.status');

        if ($inTeam !== null) {
            throw TeamRefused::because($inTeam === 'active' ? 'already_member' : 'already_member_inactive');
        }

        if (Invitation::query()->where('email', $email)->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists()) {
            throw TeamRefused::because('already_invited');
        }

        if (User::query()->where('email', $email)->exists()) {
            throw TeamRefused::because('email_unavailable');
        }
    }

    /**
     * @param  array<string, mixed>  $columns
     * @param  array<string, mixed>  $extra
     */
    private function transition(OrgMember $member, string $status, string $action, array $columns, ?string $reason, array $extra = []): void
    {
        $from = $member->status;

        DB::transaction(function () use ($member, $status, $action, $columns, $reason, $extra, $from): void {
            $member->forceFill(['status' => $status, ...$columns])->save();
            Audit::record($action, 'user', actorId: (string) auth()->id(), orgId: $this->organization()->id, entityType: 'org_members', entityId: $member->id, diff: ['status' => [$from, $status], ...$extra], reason: $reason);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{added: list<string>, removed: list<string>, role: array{0: string, 1: string}|null}
     */
    private function updateInvitation(Invitation $invitation, array $data, bool $financial, bool $acknowledged): array
    {
        $oldRole = $invitation->role;
        $newRole = $data['role'] ?? $oldRole;

        if (! in_array($newRole, Capabilities::INVITABLE_ROLES, true) || ($newRole !== $oldRole && $invitation->practitioner_id !== null)) {
            throw TeamRefused::because('role_invalid');
        }

        $before = Capabilities::effective($oldRole, $invitation->permissions, $financial);
        $wanted = array_values(array_intersect(Capabilities::all(), $data['capabilities']));
        $added = array_values(array_diff($wanted, $before));
        $removed = array_values(array_diff($before, $wanted));
        $this->assertGrantable($added, $before);
        $this->assertSafeConfiguration($newRole, $wanted, $acknowledged);
        $overrides = Capabilities::overridesFor($newRole, $wanted, $financial);

        $invitation->forceFill([
            'role' => $newRole, 'permissions' => $overrides === [] ? null : $overrides, 'preset' => $this->preset($data['preset'] ?? null, $newRole, $overrides),
            'name' => array_key_exists('name', $data) ? ($this->clean($data['name'], 120) ?? $invitation->name) : $invitation->name,
            'phone' => array_key_exists('phone', $data) ? $this->clean($data['phone'], 30) : $invitation->phone,
            'notes' => array_key_exists('notes', $data) ? $this->clean($data['notes'], 1000) : $invitation->notes,
        ])->save();

        Audit::record('team.invitation_updated', 'user', actorId: (string) auth()->id(), orgId: $this->organization()->id, entityType: 'invitations', entityId: $invitation->id, diff: ['role' => [$oldRole, $newRole], 'added' => $added, 'removed' => $removed]);

        return ['added' => $added, 'removed' => $removed, 'role' => $oldRole === $newRole ? null : [$oldRole, $newRole]];
    }

    /**
     * @throws TeamRefused
     */
    private function member(string $id): OrgMember
    {
        return OrgMember::query()->find($id) ?? throw TeamRefused::because('not_found');
    }

    /**
     * @throws TeamRefused
     */
    private function pending(string $id): Invitation
    {
        $invitation = Invitation::query()->find($id);

        if ($invitation === null || $invitation->used_at !== null || $invitation->revoked_at !== null) {
            throw TeamRefused::because('not_found');
        }

        return $invitation;
    }

    private function notify(OrgMember $member, string $event): void
    {
        $user = User::query()->find($member->user_id);

        if ($user !== null) {
            $user->notify((new MemberAccessNotification($this->organization()->name, $event))->locale($user->locale));
        }
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }

    private function roleLabel(string $role): string
    {
        return $role === OrgMember::ROLE_RECEPTIONIST ? 'assistant' : $role;
    }

    private function statusOf(string $status): string
    {
        return $status === 'disabled' ? 'suspended' : $status;
    }

    private function nameOf(OrgMember $member, ?Practitioner $practitioner): string
    {
        return (string) ($member->name ?? $practitioner->name ?? $member->getAttribute('user_email'));
    }

    /**
     * « Complet » (toutes les capacités), « étendu », « limité » ou « minimal », d'après la part de capacités accordées.
     *
     * @param  list<string>  $capabilities
     */
    private function level(array $capabilities): string
    {
        $share = count($capabilities) / max(1, count(Capabilities::all()));

        return match (true) {
            $share >= 0.95 => 'full',
            $share >= 0.55 => 'broad',
            $share >= 0.25 => 'limited',
            default => 'minimal',
        };
    }

    /**
     * Préréglage mémorisé pour l'affichage : celui choisi, ou « personnalisé » dès qu'il y a des écarts par rapport au rôle de base.
     *
     * @param  array<string, bool>  $overrides
     */
    private function preset(?string $chosen, string $role, array $overrides): string
    {
        if ($overrides !== []) {
            return 'custom';
        }

        return $role === OrgMember::ROLE_PRACTITIONER ? 'practitioner' : 'assistant';
    }

    private function clean(?string $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
