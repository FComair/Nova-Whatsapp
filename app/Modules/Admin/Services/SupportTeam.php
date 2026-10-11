<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\TeamRefused;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Notifications\SupportAgentInvitation;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Admin\Support\SupportPermissions;
use App\Modules\Support\Support\TicketStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Équipe de support (docs/32 §2) : création et invitation des agents, modification, suspension, réactivation, retrait, réaffectation de
 * leurs tickets ouverts. Réservé au Super Admin (les écrans le vérifient, ce service aussi) ; chaque action est consignée dans le journal
 * d'audit. Un agent n'a jamais accès aux comptes, à la facturation ni aux réglages : ce service ne crée que le rôle « agent ».
 *
 * Invitation : le compte est créé « invité » avec un mot de passe aléatoire inutilisable ; l'agent choisit son mot de passe via un lien à
 * usage unique (jeton aléatoire, stocké haché, valable 7 jours), puis configure son 2FA (obligatoire) à la première connexion.
 */
final class SupportTeam
{
    public const INVITATION_DAYS = 7;

    /** Valeur de « réaffecter à » qui remet les tickets dans la file sans agent. */
    public const QUEUE = 'queue';

    public function __construct(private readonly SupportConsole $console) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, internal_notes?: string|null, permissions?: array<string, mixed>, scope?: list<string>}  $data
     *
     * @throws TeamRefused
     */
    public function create(PlatformAdmin $by, array $data): PlatformAdmin
    {
        $this->authorize($by, 'support_agents.create');

        $email = mb_strtolower(trim($data['email']));
        $name = $this->clean($data['name'], 120) ?? throw TeamRefused::because('name_required');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw TeamRefused::because('email_invalid');
        }

        if (PlatformAdmin::query()->where('email', $email)->exists()) {
            throw TeamRefused::because('email_taken');
        }

        $token = Str::random(48);

        $agent = DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $data, $email, $name, $token): PlatformAdmin {
            $agent = PlatformAdmin::query()->create(['email' => $email, 'password' => Str::random(64)]);
            $agent->forceFill([
                'support_role' => PlatformAdmin::SUPPORT_AGENT,
                'name' => $name,
                'phone' => $this->clean($data['phone'] ?? null, 30),
                'internal_notes' => $this->clean($data['internal_notes'] ?? null, 2000),
                'permissions' => SupportPermissions::normalise($data['permissions'] ?? []),
                'status' => PlatformAdmin::STATUS_INVITED,
                'invited_at' => now(),
            ])->save();
            $this->setScope($agent, $data['scope'] ?? []);

            PlatformAudit::record($by->id, 'support.agent.created', null, 'platform_admin', $agent->id, [
                'email' => $email, 'name' => $name, 'role' => PlatformAdmin::SUPPORT_AGENT, 'status' => PlatformAdmin::STATUS_INVITED,
                'permissions' => $agent->permissions, 'scope' => $data['scope'] ?? [],
            ]);

            $this->issueInvitation($by, $agent, $token, reset: false, action: 'support.agent.invited');

            return $agent;
        });

        $this->mail($agent, $token, reset: false);

        return $agent;
    }

    /**
     * @param  array{name?: string, phone?: string|null, internal_notes?: string|null, permissions?: array<string, mixed>, scope?: list<string>}  $data
     * @return array<string, array{0: mixed, 1: mixed}> ce qui a changé : ancien et nouveau
     *
     * @throws TeamRefused
     */
    public function update(PlatformAdmin $by, PlatformAdmin $agent, array $data): array
    {
        $this->authorize($by, 'support_agents.edit');
        $this->mustBeAgent($agent);

        $before = ['name' => $agent->name, 'phone' => $agent->phone, 'internal_notes' => $agent->internal_notes, 'permissions' => $agent->permissions, 'scope' => $this->scopeOf($agent)];
        $new = [
            'name' => array_key_exists('name', $data) ? ($this->clean($data['name'], 120) ?? throw TeamRefused::because('name_required')) : $agent->name,
            'phone' => array_key_exists('phone', $data) ? $this->clean($data['phone'], 30) : $agent->phone,
            'internal_notes' => array_key_exists('internal_notes', $data) ? $this->clean($data['internal_notes'], 2000) : $agent->internal_notes,
            'permissions' => array_key_exists('permissions', $data) ? SupportPermissions::normalise($data['permissions']) : $agent->permissions,
            'scope' => array_key_exists('scope', $data) ? $this->normaliseScope($data['scope']) : $before['scope'],
        ];

        $changes = [];

        foreach ($new as $field => $value) {
            if ($before[$field] != $value) {
                $changes[$field] = [$before[$field], $value];
            }
        }

        if ($changes === []) {
            return [];
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $agent, $new, $changes): void {
            $agent->forceFill(['name' => $new['name'], 'phone' => $new['phone'], 'internal_notes' => $new['internal_notes'], 'permissions' => $new['permissions']])->save();
            $this->setScope($agent, $new['scope']);
            PlatformAudit::record($by->id, 'support.agent.updated', null, 'platform_admin', $agent->id, $changes);
        });

        return $changes;
    }

    /**
     * Renvoie l'invitation (compte invité) : l'ancien lien cesse de fonctionner.
     *
     * @throws TeamRefused
     */
    public function resendInvitation(PlatformAdmin $by, PlatformAdmin $agent): void
    {
        $this->authorize($by, 'support_agents.edit');
        $this->mustBeAgent($agent);

        if ($agent->status !== PlatformAdmin::STATUS_INVITED) {
            throw TeamRefused::because('not_invited');
        }

        $token = Str::random(48);
        $this->issueInvitation($by, $agent, $token, reset: false, action: 'support.agent.invitation_resent');
        $this->mail($agent, $token, reset: false);
    }

    /**
     * Envoie un lien de réinitialisation du mot de passe à un agent actif. Le 2FA de l'agent n'est pas touché.
     *
     * @throws TeamRefused
     */
    public function sendPasswordReset(PlatformAdmin $by, PlatformAdmin $agent): void
    {
        $this->authorize($by, 'support_agents.edit');
        $this->mustBeAgent($agent);

        if ($agent->status !== PlatformAdmin::STATUS_ACTIVE) {
            throw TeamRefused::because('not_active');
        }

        $token = Str::random(48);
        $this->issueInvitation($by, $agent, $token, reset: true, action: 'support.agent.password_reset_sent');
        $this->mail($agent, $token, reset: true);
    }

    /**
     * Valide un lien d'invitation / de réinitialisation et enregistre le mot de passe choisi. Le compte devient actif.
     *
     * @throws TeamRefused lien invalide, expiré ou déjà utilisé
     */
    public function accept(string $adminId, string $token, string $password): PlatformAdmin
    {
        $agent = $this->byToken($adminId, $token) ?? throw TeamRefused::because('link_invalid');

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($agent, $password): void {
            $wasInvited = $agent->status === PlatformAdmin::STATUS_INVITED;
            $agent->forceFill(['password' => $password, 'status' => PlatformAdmin::STATUS_ACTIVE, 'invitation_token' => null, 'invitation_expires_at' => null, 'remember_token' => null])->save();
            // Les agents de support et les administrateurs partagent le même lien : le journal nomme le bon type de compte.
            $prefix = $agent->support_role === null ? 'admin' : 'support.agent';
            PlatformAudit::record($agent->id, $wasInvited ? "{$prefix}.activated" : "{$prefix}.password_changed", null, 'platform_admin', $agent->id, ['status' => [$wasInvited ? PlatformAdmin::STATUS_INVITED : PlatformAdmin::STATUS_ACTIVE, PlatformAdmin::STATUS_ACTIVE]]);
        });

        return $agent;
    }

    public function byToken(string $adminId, string $token): ?PlatformAdmin
    {
        if (! Str::isUuid($adminId) || $token === '') {
            return null;
        }

        $agent = PlatformAdmin::query()->find($adminId);

        if ($agent === null || $agent->invitation_token === null || $agent->invitation_expires_at === null || $agent->invitation_expires_at->isPast()
            || ! hash_equals($agent->invitation_token, hash('sha256', $token)) || ! in_array($agent->status, [PlatformAdmin::STATUS_INVITED, PlatformAdmin::STATUS_ACTIVE], true)) {
            return null;
        }

        return $agent;
    }

    /**
     * Suspend l'agent : il perd l'accès immédiatement. Ses tickets ouverts doivent être réaffectés (à un autre agent actif ou à la file).
     *
     * @throws TeamRefused
     */
    public function suspend(PlatformAdmin $by, PlatformAdmin $agent, ?string $reassignTo, ?string $reason = null): void
    {
        $this->authorize($by, 'support_agents.suspend');
        $this->mustBeAgent($agent);

        if ($agent->status !== PlatformAdmin::STATUS_ACTIVE) {
            throw TeamRefused::because('not_active');
        }

        $moved = $this->reassign($by, $agent, $reassignTo);

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $agent, $reason, $moved): void {
            $agent->forceFill(['status' => PlatformAdmin::STATUS_SUSPENDED, 'suspended_at' => now(), 'remember_token' => null])->save();
            PlatformAudit::record($by->id, 'support.agent.suspended', null, 'platform_admin', $agent->id, ['status' => [PlatformAdmin::STATUS_ACTIVE, PlatformAdmin::STATUS_SUSPENDED], 'tickets_reassigned' => $moved], $reason);
        });
    }

    /**
     * Réactive un agent suspendu ou retiré. S'il n'a jamais choisi de mot de passe, il redevient « invité » avec une nouvelle invitation.
     *
     * @throws TeamRefused
     */
    public function reactivate(PlatformAdmin $by, PlatformAdmin $agent): void
    {
        $this->authorize($by, 'support_agents.suspend');
        $this->mustBeAgent($agent);

        if (! in_array($agent->status, [PlatformAdmin::STATUS_SUSPENDED, PlatformAdmin::STATUS_REMOVED], true)) {
            throw TeamRefused::because('not_suspended');
        }

        $from = $agent->status;
        $pending = $agent->invited_at !== null && $agent->last_login_at === null;

        if ($pending) {
            $token = Str::random(48);
            DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $agent, $from, $token): void {
                $agent->forceFill(['status' => PlatformAdmin::STATUS_INVITED, 'suspended_at' => null, 'removed_at' => null])->save();
                PlatformAudit::record($by->id, 'support.agent.reactivated', null, 'platform_admin', $agent->id, ['status' => [$from, PlatformAdmin::STATUS_INVITED]]);
                $this->issueInvitation($by, $agent, $token, reset: false, action: 'support.agent.invited');
            });
            $this->mail($agent, $token, reset: false);

            return;
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $agent, $from): void {
            $agent->forceFill(['status' => PlatformAdmin::STATUS_ACTIVE, 'suspended_at' => null, 'removed_at' => null])->save();
            PlatformAudit::record($by->id, 'support.agent.reactivated', null, 'platform_admin', $agent->id, ['status' => [$from, PlatformAdmin::STATUS_ACTIVE]]);
        });
    }

    /**
     * Retire l'agent de l'équipe : le compte est désactivé et masqué de la liste, son historique (réponses, audit) reste attribué. Ses
     * tickets ouverts doivent être réaffectés.
     *
     * @throws TeamRefused
     */
    public function remove(PlatformAdmin $by, PlatformAdmin $agent, ?string $reassignTo, ?string $reason = null): void
    {
        $this->authorize($by, 'support_agents.delete');
        $this->mustBeAgent($agent);

        if ($agent->status === PlatformAdmin::STATUS_REMOVED) {
            throw TeamRefused::because('already_removed');
        }

        $moved = $this->reassign($by, $agent, $reassignTo);
        $from = $agent->status;

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $agent, $reason, $moved, $from): void {
            $agent->forceFill(['status' => PlatformAdmin::STATUS_REMOVED, 'removed_at' => now(), 'invitation_token' => null, 'invitation_expires_at' => null, 'remember_token' => null])->save();
            PlatformAudit::record($by->id, 'support.agent.removed', null, 'platform_admin', $agent->id, ['status' => [$from, PlatformAdmin::STATUS_REMOVED], 'tickets_reassigned' => $moved], $reason);
        });
    }

    /**
     * Tickets ouverts (non résolus, non fermés) confiés à l'agent.
     */
    public function openTicketCount(PlatformAdmin $agent): int
    {
        return PlatformSupportTicket::query()->where('assigned_admin_id', $agent->id)->whereIn('status', TicketStatus::ACTIVE)->count();
    }

    /**
     * Réaffecte les tickets ouverts de l'agent. Refuse sans destination quand il y en a, ou si l'agent visé ne peut pas recevoir l'un
     * d'eux (inactif, hors périmètre) : rien n'est alors modifié.
     *
     * @return int nombre de tickets réaffectés
     *
     * @throws TeamRefused
     */
    private function reassign(PlatformAdmin $by, PlatformAdmin $agent, ?string $to): int
    {
        $tickets = PlatformSupportTicket::query()->where('assigned_admin_id', $agent->id)->whereIn('status', TicketStatus::ACTIVE)->get();

        if ($tickets->isEmpty()) {
            return 0;
        }

        if ($to === null || $to === '') {
            throw TeamRefused::because('reassign_required');
        }

        $target = $to === self::QUEUE ? null : $to;

        if ($target !== null) {
            if ($target === $agent->id) {
                throw TeamRefused::because('reassign_invalid');
            }

            foreach ($tickets->pluck('org_id')->unique() as $orgId) {
                if (! array_key_exists($target, SupportAccess::assignees((string) $orgId))) {
                    throw TeamRefused::because('reassign_invalid');
                }
            }
        }

        foreach ($tickets as $ticket) {
            $this->console->assign($by, $ticket, $target);
        }

        return $tickets->count();
    }

    /**
     * Enregistre le jeton (haché) d'une invitation ou d'une réinitialisation et la trace. Aussi utilisé pour les administrateurs (docs/38).
     */
    public function issueInvitation(PlatformAdmin $by, PlatformAdmin $agent, string $token, bool $reset, string $action): void
    {
        $agent->forceFill(['invitation_token' => hash('sha256', $token), 'invitation_expires_at' => now()->addDays(self::INVITATION_DAYS), 'invited_at' => $agent->invited_at ?? now()])->save();
        PlatformAudit::record($by->id, $action, null, 'platform_admin', $agent->id, ['email' => $agent->email, 'expires_at' => $agent->invitation_expires_at?->toIso8601String(), 'reset' => $reset]);
    }

    private function mail(PlatformAdmin $agent, string $token, bool $reset): void
    {
        $url = URL::route('admin.invitation', ['admin' => $agent->id, 'token' => $token]);
        $agent->notify(new SupportAgentInvitation($url, $reset, self::INVITATION_DAYS));
    }

    /**
     * @param  list<string>  $orgIds
     */
    private function setScope(PlatformAdmin $agent, array $orgIds): void
    {
        $orgIds = $this->normaliseScope($orgIds);
        $table = DB::connection(PlatformAudit::CONNECTION)->table('platform_admin_client_scopes');
        (clone $table)->where('admin_id', $agent->id)->delete();

        if ($orgIds !== []) {
            $table->insert(array_map(fn (string $orgId): array => ['admin_id' => $agent->id, 'org_id' => $orgId, 'created_at' => now()], $orgIds));
        }
    }

    /**
     * @param  list<string>  $orgIds
     * @return list<string>
     */
    private function normaliseScope(array $orgIds): array
    {
        $orgIds = array_values(array_unique(array_filter(array_map('strval', $orgIds), fn (string $id): bool => Str::isUuid($id))));
        sort($orgIds);

        return $orgIds;
    }

    /**
     * @return list<string>
     */
    public function scopeOf(PlatformAdmin $agent): array
    {
        $ids = DB::connection(PlatformAudit::CONNECTION)->table('platform_admin_client_scopes')->where('admin_id', $agent->id)->pluck('org_id')->map(fn ($id): string => (string) $id)->all();
        sort($ids);

        return $ids;
    }

    private function clean(?string $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * Un compte à rôle restreint n'a jamais accès à l'équipe ; un administrateur doit en plus avoir la permission de l'action (docs/38).
     *
     * @throws TeamRefused
     */
    private function authorize(PlatformAdmin $by, string $permission): void
    {
        if ($by->support_role !== null || ! AdminAccess::can($permission, $by)) {
            throw TeamRefused::because('forbidden');
        }
    }

    /** @throws TeamRefused la gestion d'équipe ne touche jamais un Super Admin ni un éditeur de contenu */
    private function mustBeAgent(PlatformAdmin $agent): void
    {
        if (! $agent->isSupportAgent()) {
            throw TeamRefused::because('not_agent');
        }
    }
}
