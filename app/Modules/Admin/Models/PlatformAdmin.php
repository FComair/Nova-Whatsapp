<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SensitiveParameter;

/**
 * Compte Super Admin (fondateur). Table, guard (`platform`) et connexion PostgreSQL (`pgsql_platform`) distincts du client.
 * Aucun accès par le rôle applicatif client : voir la migration identity_tables.
 *
 * @property string $id
 * @property string $email
 * @property string|null $app_authentication_secret
 * @property list<string>|null $app_authentication_recovery_codes
 */
#[Fillable(['email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class PlatformAdmin extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasName
{
    use HasUuids, InteractsWithAppAuthentication;

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
            'ip_allowlist' => 'array',
        ];
    }

    /** Nom affiché dans le menu du panel : l'adresse e-mail (pas de nom saisi). */
    public function getFilamentName(): string
    {
        return $this->email;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Toute ligne de platform_admins est un administrateur de la plateforme ; le 2FA est imposé par le panel.
        return $panel->getId() === 'admin';
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
