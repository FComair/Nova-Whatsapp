<?php

declare(strict_types=1);

namespace App\Modules\Admin\Listeners;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\PlatformAudit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Connexions du Super Admin (RGPD art. 5.2 et 32 : traçabilité des accès privilégiés).
 *
 *  - Connexion réussie : `platform.login` dans le journal d'audit (adresse IP et agent déjà enregistrés par `PlatformAudit`), et
 *    `last_login_at` mis à jour (sert à repérer les comptes dormants).
 *  - Échec : `platform.login_failed`, rattaché au compte s'il existe. L'adresse saisie n'est jamais gardée en clair pour un compte
 *    inconnu (empreinte seulement) et le mot de passe n'est jamais lu.
 */
final class RecordAdminAuthentication
{
    private const GUARD = 'platform';

    public function succeeded(Login $event): void
    {
        if ($event->guard !== self::GUARD || ! $event->user instanceof PlatformAdmin) {
            return;
        }

        DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->where('id', $event->user->id)->update(['last_login_at' => now()]);
        PlatformAudit::record($event->user->id, 'platform.login', null, 'platform_admin', $event->user->id);
    }

    public function failed(Failed $event): void
    {
        if ($event->guard !== self::GUARD) {
            return;
        }

        $email = strtolower(trim((string) ($event->credentials['email'] ?? '')));
        $admin = $event->user instanceof PlatformAdmin ? $event->user : null;

        if ($admin !== null) {
            PlatformAudit::record($admin->id, 'platform.login_failed', null, 'platform_admin', $admin->id);

            return;
        }

        PlatformAudit::system('platform.login_failed', null, 'platform_admin', null, ['email_sha256' => $email === '' ? null : hash('sha256', $email), 'ip' => request()->ip()]);
    }
}
