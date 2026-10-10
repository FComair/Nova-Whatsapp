<?php

declare(strict_types=1);

namespace App\Modules\Staff\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Invitation;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Notifications\PractitionerInvitationNotification;
use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use stdClass;

/**
 * Invitation d'un praticien par lien (flowcharts 1C et 5B/E13).
 *
 * - Le lien est valable 3 jours ; une nouvelle invitation invalide l'ancienne (E13).
 * - Le jeton n'est jamais stocké en clair (empreinte SHA-256) et n'apparaît que dans l'e-mail.
 * - L'acceptation crée le compte du praticien (e-mail vérifié par le lien) et le rattache à l'organisation.
 */
final class InvitationService
{
    public function __construct(private readonly PractitionerManager $manager, private readonly TenantContext $tenant) {}

    /**
     * Crée le praticien (en brouillon si la limite de l'offre est atteinte) et lui envoie son invitation.
     *
     * @return array{practitioner: Practitioner, invitation: Invitation, over_limit: bool}
     */
    public function invite(Organization $organization, string $name, string $email, ?string $invitedBy): array
    {
        $colors = config('nova.practitioner_colors');

        $created = $this->manager->create($organization, [
            'name' => $name,
            'color' => $colors[Practitioner::query()->count() % count($colors)],
            'service_ids' => [],
            'copy_hours' => false, // le praticien renseigne lui-même ses disponibilités
        ]);

        $invitation = $this->issue($organization, $created['practitioner'], $email, $invitedBy);

        return ['practitioner' => $created['practitioner'], 'invitation' => $invitation, 'over_limit' => $created['over_limit']];
    }

    /**
     * Invite un membre de l'équipe (docs/34). Un praticien reçoit sa fiche praticien (brouillon si la limite de l'offre est atteinte) ; un
     * assistant n'en a pas. Le nom, le téléphone, le préréglage, les écarts de permissions et les notes voyagent avec l'invitation.
     *
     * @param  array{name: string, email: string, role: string, phone?: string|null, preset?: string|null, permissions?: array<string, bool>|null, notes?: string|null}  $data
     * @return array{practitioner: Practitioner|null, invitation: Invitation, over_limit: bool}
     */
    public function inviteMember(Organization $organization, array $data, ?string $invitedBy): array
    {
        $practitioner = null;
        $overLimit = false;

        if ($data['role'] === OrgMember::ROLE_PRACTITIONER) {
            $colors = config('nova.practitioner_colors');
            $created = $this->manager->create($organization, [
                'name' => $data['name'],
                'color' => $colors[Practitioner::query()->count() % count($colors)],
                'service_ids' => [],
                'copy_hours' => false,
            ]);
            $practitioner = $created['practitioner'];
            $overLimit = $created['over_limit'];
        }

        $invitation = $this->issue($organization, $practitioner, $data['email'], $invitedBy, [
            'role' => $data['role'], 'name' => $data['name'], 'phone' => $data['phone'] ?? null, 'preset' => $data['preset'] ?? null,
            'permissions' => $data['permissions'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return ['practitioner' => $practitioner, 'invitation' => $invitation, 'over_limit' => $overLimit];
    }

    /**
     * Renvoie une invitation en attente (nouveau lien, l'ancien est invalidé), avec les mêmes réglages.
     */
    public function resendInvitation(Organization $organization, Invitation $invitation, ?string $invitedBy): Invitation
    {
        if ($invitation->used_at !== null || $invitation->revoked_at !== null) {
            throw new InvalidArgumentException('Cette invitation n\'est plus en attente.');
        }

        $practitioner = $invitation->practitioner_id === null ? null : Practitioner::query()->find($invitation->practitioner_id);

        return $this->issue($organization, $practitioner, $invitation->email, $invitedBy, [
            'role' => $invitation->role, 'name' => $invitation->name, 'phone' => $invitation->phone, 'preset' => $invitation->preset,
            'permissions' => $invitation->permissions, 'notes' => $invitation->notes,
        ]);
    }

    /**
     * Annule une invitation en attente : le lien ne fonctionne plus.
     */
    public function cancel(Organization $organization, Invitation $invitation, ?string $cancelledBy): void
    {
        if ($invitation->used_at !== null || $invitation->revoked_at !== null) {
            throw new InvalidArgumentException('Cette invitation n\'est plus en attente.');
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        Audit::record('team.invitation_cancelled', 'user', actorId: $cancelledBy, orgId: $organization->id, entityType: 'invitations', entityId: $invitation->id, diff: ['email' => $invitation->email, 'role' => $invitation->role]);
    }

    /**
     * Nouvelle invitation pour un praticien qui n'a pas encore de compte ; l'ancienne est invalidée (E13).
     */
    public function resend(Organization $organization, Practitioner $practitioner, ?string $invitedBy): Invitation
    {
        $last = Invitation::query()->where('practitioner_id', $practitioner->id)->latest('created_at')->first();

        if ($practitioner->user_id !== null || $last === null) {
            throw new InvalidArgumentException('Ce praticien ne peut pas être ré-invité.');
        }

        return $this->issue($organization, $practitioner, $last->email, $invitedBy);
    }

    /**
     * Recherche une invitation par son jeton, avant toute connexion : via une fonction SQL étroite qui ne renvoie que
     * la ligne correspondant à l'empreinte du jeton.
     */
    public function lookup(string $token): ?stdClass
    {
        return DB::selectOne('select * from invitation_lookup(?)', [Invitation::hash($token)]);
    }

    /**
     * @param  array{password: string}  $data
     *
     * @throws InvitationInvalid
     */
    public function accept(string $token, array $data): User
    {
        $row = $this->lookup($token);

        if ($row === null) {
            throw new InvitationInvalid;
        }

        return $this->tenant->run($row->org_id, function () use ($row, $data): User {
            return DB::transaction(function () use ($row, $data): User {
                // Verrou : deux clics simultanés ne doivent pas créer deux comptes.
                $invitation = Invitation::query()->lockForUpdate()->find($row->id);

                if ($invitation === null || ! $invitation->isUsable()) {
                    throw new InvitationInvalid;
                }

                $organization = Organization::query()->findOrFail($invitation->org_id);
                $practitioner = $invitation->practitioner_id === null ? null : Practitioner::query()->findOrFail($invitation->practitioner_id);

                $user = User::query()->create([
                    'email' => $invitation->email,
                    'password' => $data['password'],
                    'locale' => $organization->locale,
                ]);
                // L'adresse est prouvée par la réception du lien : pas de vérification supplémentaire.
                $user->forceFill(['email_verified_at' => now()])->save();

                OrgMember::query()->create([
                    'org_id' => $organization->id, 'user_id' => $user->id, 'role' => $invitation->role ?: OrgMember::ROLE_PRACTITIONER, 'status' => 'active',
                    'name' => $invitation->name ?? $practitioner?->name, 'phone' => $invitation->phone, 'preset' => $invitation->preset,
                    'permissions' => $invitation->permissions === null ? null : Capabilities::clean($invitation->permissions), 'notes' => $invitation->notes,
                    'invited_at' => $invitation->created_at, 'invited_by' => $invitation->invited_by,
                ]);
                $practitioner?->forceFill(['user_id' => $user->id])->save();
                $invitation->forceFill(['used_at' => now()])->save();

                Audit::record('invitation.accepted', 'user', actorId: $user->id, orgId: $organization->id, entityType: $practitioner === null ? 'invitations' : 'practitioner', entityId: $practitioner->id ?? $invitation->id, diff: ['role' => $invitation->role]);

                return $user;
            });
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issue(Organization $organization, ?Practitioner $practitioner, string $email, ?string $invitedBy, array $extra = []): Invitation
    {
        $token = Str::random(48);
        $email = mb_strtolower(trim($email));

        $invitation = DB::transaction(function () use ($organization, $practitioner, $email, $invitedBy, $token, $extra): Invitation {
            // L'ancienne invitation en attente est invalidée (E13 : « génère une nouvelle invitation et invalide l'ancienne »).
            Invitation::query()->where(fn ($q) => $q->where('email', $email)->when($practitioner !== null, fn ($inner) => $inner->orWhere('practitioner_id', $practitioner?->id)))
                ->whereNull('used_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $invitation = Invitation::query()->create([
                'org_id' => $organization->id,
                'practitioner_id' => $practitioner?->id,
                'invited_by' => $invitedBy,
                'email' => $email,
                'token_hash' => Invitation::hash($token),
                'expires_at' => now()->addDays((int) config('nova.security.invitation_ttl_days')),
                'role' => $extra['role'] ?? OrgMember::ROLE_PRACTITIONER,
                'name' => $extra['name'] ?? $practitioner?->name,
                'phone' => $extra['phone'] ?? null,
                'preset' => $extra['preset'] ?? null,
                'permissions' => ($extra['permissions'] ?? []) === [] ? null : Capabilities::clean((array) $extra['permissions']),
                'notes' => $extra['notes'] ?? null,
            ]);

            Audit::record('invitation.sent', 'user', actorId: $invitedBy, orgId: $organization->id, entityType: $practitioner === null ? 'invitations' : 'practitioner', entityId: $practitioner->id ?? $invitation->id, diff: ['email' => $email, 'role' => $invitation->role, 'preset' => $invitation->preset, 'permissions' => $invitation->permissions]);

            return $invitation;
        });

        Notification::route('mail', $email)->notify((new PractitionerInvitationNotification($organization->name, $invitation->name ?? $practitioner->name ?? $email, $token, $invitation->role))->locale($organization->locale));

        return $invitation;
    }
}
