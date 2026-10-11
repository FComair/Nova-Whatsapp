<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\AdminRefused;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Notifications\AdminAccountInvitation;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\AdminPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profils et accès des administrateurs du panel (docs/38) : invitation, modification du rôle et des permissions, suspension, réactivation,
 * retrait, réinitialisation du mot de passe, déconnexion forcée. Les agents de support et les éditeurs de contenu ne relèvent pas d'ici
 * (`SupportTeam`).
 *
 * Règles qui ne se contournent pas :
 *  - seul un Super Admin actif gère les administrateurs (`admins.*` n'est accordée à aucun autre rôle) : aucun administrateur de rang inférieur ne
 *    peut donc toucher un Super Admin, ni se promouvoir ;
 *  - on ne se retire pas son propre accès (rôle, permissions, suspension, retrait, déconnexion) et on ne retire jamais le dernier Super Admin actif ;
 *  - un compte suspendu ou retiré est mis dehors tout de suite (sessions révoquées) ; chaque action est consignée dans le journal d'audit.
 *
 * Invitation : compte créé « invité » avec un mot de passe aléatoire inutilisable, lien à usage unique (jeton haché, 7 jours) pour choisir un mot de
 * passe, puis 2FA obligatoire à la première connexion (même parcours que l'équipe de support).
 */
final class AdminTeam
{
    public const INVITATION_DAYS = SupportTeam::INVITATION_DAYS;

    public function __construct(private readonly SupportTeam $links) {}

    /**
     * @param  array{name: string, email: string, role: string, phone?: string|null, internal_notes?: string|null, permissions?: array<array-key, mixed>}  $data
     *
     * @throws AdminRefused
     */
    public function create(PlatformAdmin $by, array $data): PlatformAdmin
    {
        $this->authorize($by, 'admins.create');

        $email = mb_strtolower(trim($data['email']));
        $name = $this->clean($data['name'], 120) ?? throw AdminRefused::because('name_required');
        $role = $this->role($data['role']);
        $permissions = $this->permissionsFor($role, $data['permissions'] ?? []);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw AdminRefused::because('email_invalid');
        }

        if (PlatformAdmin::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw AdminRefused::because('email_taken');
        }

        $token = Str::random(48);

        $admin = DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $data, $email, $name, $role, $permissions, $token): PlatformAdmin {
            $admin = PlatformAdmin::query()->create(['email' => $email, 'password' => Str::random(64)]);
            $admin->forceFill([
                'admin_role' => $role, 'name' => $name, 'phone' => $this->clean($data['phone'] ?? null, 30), 'internal_notes' => $this->clean($data['internal_notes'] ?? null, 2000),
                'permissions' => $permissions, 'status' => PlatformAdmin::STATUS_INVITED, 'invited_at' => now(), 'created_by' => $by->id,
            ])->save();

            PlatformAudit::record($by->id, 'admin.created', null, 'platform_admin', $admin->id, ['email' => $email, 'name' => $name, 'role' => $role, 'permissions' => AdminPermissions::forRole($role, $permissions), 'status' => PlatformAdmin::STATUS_INVITED]);
            $this->links->issueInvitation($by, $admin, $token, reset: false, action: 'admin.invited');

            return $admin;
        });

        $this->mail($admin, $token, reset: false);

        return $admin;
    }

    /**
     * @param  array{name?: string, phone?: string|null, internal_notes?: string|null, role?: string, permissions?: array<array-key, mixed>}  $data
     * @return array<string, array{0: mixed, 1: mixed}> ce qui a changé : ancien et nouveau
     *
     * @throws AdminRefused
     */
    public function update(PlatformAdmin $by, PlatformAdmin $admin, array $data): array
    {
        $this->authorize($by, 'admins.edit');
        $this->mustBeAdmin($admin);

        if ($admin->status === PlatformAdmin::STATUS_REMOVED) {
            throw AdminRefused::because('removed');
        }

        $role = array_key_exists('role', $data) ? $this->role($data['role']) : $admin->adminRole();
        $permissions = $role === AdminPermissions::CUSTOM
            ? $this->permissionsFor($role, array_key_exists('permissions', $data) ? $data['permissions'] : ($admin->permissions ?? []))
            : null;

        $before = ['name' => $admin->name, 'phone' => $admin->phone, 'internal_notes' => $admin->internal_notes, 'role' => $admin->adminRole(), 'permissions' => AdminPermissions::forRole($admin->adminRole(), $admin->permissions)];
        $new = [
            'name' => array_key_exists('name', $data) ? ($this->clean($data['name'], 120) ?? throw AdminRefused::because('name_required')) : $admin->name,
            'phone' => array_key_exists('phone', $data) ? $this->clean($data['phone'], 30) : $admin->phone,
            'internal_notes' => array_key_exists('internal_notes', $data) ? $this->clean($data['internal_notes'], 2000) : $admin->internal_notes,
            'role' => $role,
            'permissions' => AdminPermissions::forRole($role, $permissions),
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

        $accessChanged = isset($changes['role']) || isset($changes['permissions']);

        if ($accessChanged) {
            // Se retirer son propre accès, ou retirer le dernier Super Admin actif, enfermerait tout le monde dehors.
            $this->mustNotBeSelf($by, $admin, 'self_access');

            if ($before['role'] === AdminPermissions::SUPER_ADMIN && $role !== AdminPermissions::SUPER_ADMIN) {
                $this->mustLeaveASuperAdmin($admin);
            }
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $admin, $new, $permissions, $changes, $accessChanged): void {
            $admin->forceFill(['name' => $new['name'], 'phone' => $new['phone'], 'internal_notes' => $new['internal_notes'], 'admin_role' => $new['role'], 'permissions' => $permissions])->save();
            PlatformAudit::record($by->id, 'admin.updated', null, 'platform_admin', $admin->id, $changes);

            if ($accessChanged) {
                PlatformAudit::record($by->id, 'admin.access_changed', null, 'platform_admin', $admin->id, array_intersect_key($changes, ['role' => 1, 'permissions' => 1]));
            }
        });

        return $changes;
    }

    /**
     * @throws AdminRefused
     */
    public function resendInvitation(PlatformAdmin $by, PlatformAdmin $admin): void
    {
        $this->authorize($by, 'admins.edit');
        $this->mustBeAdmin($admin);

        if ($admin->status !== PlatformAdmin::STATUS_INVITED) {
            throw AdminRefused::because('not_invited');
        }

        $token = Str::random(48);
        $this->links->issueInvitation($by, $admin, $token, reset: false, action: 'admin.invitation_resent');
        $this->mail($admin, $token, reset: false);
    }

    /**
     * Lien de réinitialisation du mot de passe pour un administrateur actif. Quand il choisit son nouveau mot de passe, ses autres sessions tombent
     * (le panel compare l'empreinte du mot de passe) ; son 2FA n'est pas touché.
     *
     * @throws AdminRefused
     */
    public function sendPasswordReset(PlatformAdmin $by, PlatformAdmin $admin): void
    {
        $this->authorize($by, 'admins.edit');
        $this->mustBeAdmin($admin);
        $this->mustNotBeSelf($by, $admin, 'self_action');

        if ($admin->status !== PlatformAdmin::STATUS_ACTIVE) {
            throw AdminRefused::because('not_active');
        }

        $token = Str::random(48);
        $this->links->issueInvitation($by, $admin, $token, reset: true, action: 'admin.password_reset_sent');
        $this->mail($admin, $token, reset: true);
    }

    /**
     * Ferme toutes les sessions ouvertes du compte : toute session antérieure à cet instant est refusée à la requête suivante.
     *
     * @throws AdminRefused
     */
    public function forceLogout(PlatformAdmin $by, PlatformAdmin $admin): void
    {
        $this->authorize($by, 'admins.edit');
        $this->mustBeAdmin($admin);
        $this->mustNotBeSelf($by, $admin, 'self_action');

        if ($admin->status === PlatformAdmin::STATUS_REMOVED) {
            throw AdminRefused::because('removed');
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $admin): void {
            $admin->forceFill(['sessions_revoked_at' => now(), 'remember_token' => null])->save();
            PlatformAudit::record($by->id, 'admin.sessions_revoked', null, 'platform_admin', $admin->id);
        });
    }

    /**
     * Suspend le compte : plus d'accès, sessions fermées tout de suite.
     *
     * @throws AdminRefused
     */
    public function suspend(PlatformAdmin $by, PlatformAdmin $admin, ?string $reason = null): void
    {
        $this->authorize($by, 'admins.suspend');
        $this->mustBeAdmin($admin);
        $this->mustNotBeSelf($by, $admin, 'self_access');

        if ($admin->status !== PlatformAdmin::STATUS_ACTIVE) {
            throw AdminRefused::because('not_active');
        }

        $this->mustLeaveASuperAdmin($admin);

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $admin, $reason): void {
            $admin->forceFill(['status' => PlatformAdmin::STATUS_SUSPENDED, 'suspended_at' => now(), 'sessions_revoked_at' => now(), 'remember_token' => null])->save();
            PlatformAudit::record($by->id, 'admin.suspended', null, 'platform_admin', $admin->id, ['status' => [PlatformAdmin::STATUS_ACTIVE, PlatformAdmin::STATUS_SUSPENDED]], $reason);
        });
    }

    /**
     * Réactive un compte suspendu ou retiré. Sans mot de passe jamais choisi, il redevient « invité » avec une nouvelle invitation.
     *
     * @throws AdminRefused
     */
    public function reactivate(PlatformAdmin $by, PlatformAdmin $admin): void
    {
        $this->authorize($by, 'admins.suspend');
        $this->mustBeAdmin($admin);

        if (! in_array($admin->status, [PlatformAdmin::STATUS_SUSPENDED, PlatformAdmin::STATUS_REMOVED], true)) {
            throw AdminRefused::because('not_suspended');
        }

        $from = $admin->status;
        $pending = $admin->invited_at !== null && $admin->last_login_at === null;
        $token = $pending ? Str::random(48) : null;

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $admin, $from, $pending, $token): void {
            $to = $pending ? PlatformAdmin::STATUS_INVITED : PlatformAdmin::STATUS_ACTIVE;
            $admin->forceFill(['status' => $to, 'suspended_at' => null, 'removed_at' => null])->save();
            PlatformAudit::record($by->id, 'admin.reactivated', null, 'platform_admin', $admin->id, ['status' => [$from, $to]]);

            if ($token !== null) {
                $this->links->issueInvitation($by, $admin, $token, reset: false, action: 'admin.invited');
            }
        });

        if ($token !== null) {
            $this->mail($admin, $token, reset: false);
        }
    }

    /**
     * Retire le compte : désactivé et masqué de la liste, son historique reste attribué (suppression douce), ses sessions sont fermées.
     *
     * @throws AdminRefused
     */
    public function remove(PlatformAdmin $by, PlatformAdmin $admin, ?string $reason = null): void
    {
        $this->authorize($by, 'admins.delete');
        $this->mustBeAdmin($admin);
        $this->mustNotBeSelf($by, $admin, 'self_access');

        if ($admin->status === PlatformAdmin::STATUS_REMOVED) {
            throw AdminRefused::because('removed');
        }

        if ($admin->status === PlatformAdmin::STATUS_ACTIVE) {
            $this->mustLeaveASuperAdmin($admin);
        }

        $from = $admin->status;

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($by, $admin, $from, $reason): void {
            $admin->forceFill(['status' => PlatformAdmin::STATUS_REMOVED, 'removed_at' => now(), 'invitation_token' => null, 'invitation_expires_at' => null, 'sessions_revoked_at' => now(), 'remember_token' => null])->save();
            PlatformAudit::record($by->id, 'admin.removed', null, 'platform_admin', $admin->id, ['status' => [$from, PlatformAdmin::STATUS_REMOVED]], $reason);
        });
    }

    /**
     * Export CSV de la liste filtrée (sans aucun secret) ; l'export est tracé.
     *
     * @param  Builder<PlatformAdmin>  $query
     */
    public function export(PlatformAdmin $by, Builder $query): StreamedResponse
    {
        $this->authorize($by, 'admins.view');

        /** @var Collection<int, PlatformAdmin> $rows */
        $rows = $query->limit(5000)->get();
        PlatformAudit::record($by->id, 'admin.exported', null, 'platform_admin', null, ['rows' => $rows->count()]);
        $creators = DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->pluck('email', 'id');

        return response()->streamDownload(function () use ($rows, $creators): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['name', 'email', 'role', 'status', 'permissions', 'last_login_at', 'created_at', 'created_by'], ',', '"', '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safeCell(...), [
                    $row->name, $row->email, $row->adminRole(), $row->status, count(AdminPermissions::forRole($row->adminRole(), $row->permissions)),
                    $row->last_login_at?->toIso8601String(), $row->created_at?->toIso8601String(), $row->created_by === null ? '' : ($creators[$row->created_by] ?? ''),
                ]), ',', '"', '');
            }

            fclose($out);
        }, 'admins-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Une cellule commençant par = + - @ serait lue comme une formule par un tableur : on la neutralise. */
    private static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * Activité du compte pour sa fiche : dernières actions, dernières connexions et compteurs de travail.
     *
     * @return array{activity: list<object>, logins: list<object>, tickets_handled: int, clients_managed: int, actions: int}
     */
    public function activity(PlatformAdmin $admin): array
    {
        $db = DB::connection(PlatformAudit::CONNECTION);
        $own = fn () => $db->table('audit_logs')->where('actor_id', $admin->id);

        return [
            'activity' => $own()->whereNotIn('action', ['platform.login', 'platform.login_failed'])->orderByDesc('created_at')->limit(15)->get(['action', 'entity_type', 'created_at', 'ip'])->all(),
            'logins' => $own()->whereIn('action', ['platform.login', 'platform.login_failed'])->orderByDesc('created_at')->limit(8)->get(['action', 'created_at', 'ip', 'user_agent'])->all(),
            'tickets_handled' => (int) $db->table('support_tickets')->where('assigned_admin_id', $admin->id)->count(),
            'clients_managed' => (int) $own()->whereNotNull('org_id')->distinct()->count('org_id'),
            'actions' => (int) $own()->count(),
        ];
    }

    private function mail(PlatformAdmin $admin, string $token, bool $reset): void
    {
        $url = URL::route('admin.invitation', ['admin' => $admin->id, 'token' => $token]);
        $admin->notify(new AdminAccountInvitation($url, $reset, self::INVITATION_DAYS, __("admins.roles.{$admin->adminRole()}.name")));
    }

    /** @throws AdminRefused */
    private function authorize(PlatformAdmin $by, string $permission): void
    {
        try {
            AdminAccess::authorize($by, $permission);
        } catch (AuthorizationException) {
            throw AdminRefused::because('forbidden');
        }
    }

    /** @throws AdminRefused */
    private function role(mixed $role): string
    {
        return is_string($role) && AdminPermissions::isRole($role) ? $role : throw AdminRefused::because('role_invalid');
    }

    /**
     * Permissions à enregistrer pour un rôle : la liste choisie pour `custom` (au moins une), rien pour un rôle prédéfini (son ensemble est fixe).
     *
     * @param  array<array-key, mixed>  $chosen
     * @return list<string>|null
     *
     * @throws AdminRefused
     */
    private function permissionsFor(string $role, array $chosen): ?array
    {
        if ($role !== AdminPermissions::CUSTOM) {
            return null;
        }

        $permissions = AdminPermissions::normalise($chosen);

        return $permissions === [] ? throw AdminRefused::because('permissions_required') : $permissions;
    }

    /** @throws AdminRefused la gestion des administrateurs ne touche jamais un agent de support ni un éditeur de contenu */
    private function mustBeAdmin(PlatformAdmin $admin): void
    {
        if ($admin->support_role !== null) {
            throw AdminRefused::because('not_admin');
        }
    }

    /** @throws AdminRefused */
    private function mustNotBeSelf(PlatformAdmin $by, PlatformAdmin $admin, string $reason): void
    {
        if ($by->id === $admin->id) {
            throw AdminRefused::because($reason);
        }
    }

    /**
     * Le compte visé ne doit pas être le dernier Super Admin actif.
     *
     * @throws AdminRefused
     */
    private function mustLeaveASuperAdmin(PlatformAdmin $admin): void
    {
        if (! $admin->isSuperAdmin() || ! $admin->isActive()) {
            return;
        }

        $others = PlatformAdmin::query()->whereNull('support_role')->where('status', PlatformAdmin::STATUS_ACTIVE)->whereKeyNot($admin->id)
            ->where(fn (Builder $query) => $query->whereNull('admin_role')->orWhere('admin_role', AdminPermissions::SUPER_ADMIN))->exists();

        if (! $others) {
            throw AdminRefused::because('last_super_admin');
        }
    }

    private function clean(?string $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
