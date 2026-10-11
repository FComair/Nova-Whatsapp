<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Admin\Support\AdminPermissions;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * Compte Super Admin (fondateur). Table, guard (`platform`) et connexion PostgreSQL (`pgsql_platform`) distincts du client.
 * Aucun accès par le rôle applicatif client : voir la migration identity_tables.
 *
 * @property string $id
 * @property string $email
 * @property string|null $app_authentication_secret
 * @property list<string>|null $app_authentication_recovery_codes
 * @property string|null $support_role
 * @property array<string, list<string>>|null $support_notifications
 * @property string|null $name
 * @property string|null $phone
 * @property string $status
 * @property string|null $internal_notes
 * @property array<array-key, mixed>|null $permissions agent : clé => booléen ; administrateur à rôle `custom` : liste de `module.action`
 * @property string|null $admin_role
 * @property string|null $created_by
 * @property string|null $last_login_ip
 * @property Carbon|null $sessions_revoked_at
 * @property string|null $invitation_token
 * @property Carbon|null $invitation_expires_at
 * @property Carbon|null $invited_at
 * @property Carbon|null $last_login_at
 */
#[Fillable(['email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class PlatformAdmin extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    use HasUuids, InteractsWithAppAuthentication, Notifiable;

    /** Rôle de support limité aux tickets (docs/29 §5). Sans rôle : Super Admin, accès complet. */
    public const SUPPORT_AGENT = 'agent';

    /** Éditeur de contenu : FAQ et catégories d'aide seulement (docs/30 §7). */
    public const SUPPORT_EDITOR = 'editor';

    /** Statut d'un compte de l'équipe : seul « actif » ouvre le panel ; « retiré » est une suppression douce (l'historique garde l'auteur). */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITED = 'invited';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REMOVED = 'removed';

    protected $connection = 'pgsql_platform';

    protected $table = 'platform_admins';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'app_authentication_recovery_codes' => 'encrypted:array',
            'last_login_at' => 'datetime',
            'sessions_revoked_at' => 'datetime',
            'ip_allowlist' => 'array',
            'support_notifications' => 'array',
            'permissions' => 'array',
            'invitation_expires_at' => 'datetime',
            'invited_at' => 'datetime',
            'suspended_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * Notifications du panel : table réservée au rôle `nova_platform` (la table `notifications` par défaut serait lisible par le rôle client).
     *
     * @return MorphMany<PlatformNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(PlatformNotification::class, 'notifiable')->latest();
    }

    /**
     * Rôle d'administrateur (docs/38). Un compte historique sans rôle enregistré est Super Admin ; un agent ou un éditeur n'en a pas.
     */
    public function adminRole(): string
    {
        return $this->support_role !== null ? '' : ($this->admin_role ?? AdminPermissions::SUPER_ADMIN);
    }

    public function isSuperAdmin(): bool
    {
        return $this->adminRole() === AdminPermissions::SUPER_ADMIN;
    }

    public function isSupportAgent(): bool
    {
        return $this->support_role === self::SUPPORT_AGENT;
    }

    public function isContentEditor(): bool
    {
        return $this->support_role === self::SUPPORT_EDITOR;
    }

    /** Nom affiché dans le menu du panel : le nom saisi, à défaut l'adresse e-mail. */
    public function getFilamentName(): string
    {
        return $this->name !== null && $this->name !== '' ? $this->name : $this->email;
    }

    public function isActive(): bool
    {
        // Une instance fraîchement créée n'a pas relu le défaut de la base : sans statut chargé, le compte est actif.
        return ($this->status ?? self::STATUS_ACTIVE) === self::STATUS_ACTIVE;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Toute ligne active de platform_admins est un administrateur de la plateforme ; le 2FA est imposé par le panel.
        // Un compte suspendu, retiré ou pas encore activé perd l'accès à la requête suivante, session ouverte comprise.
        return $panel->getId() === 'admin' && $this->isActive();
    }

    /**
     * @return list<string>|null
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  list<string>|null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}
