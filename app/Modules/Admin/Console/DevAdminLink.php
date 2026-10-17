<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Lien de connexion directe au Super Admin, POUR LES ESSAIS EN DÉVELOPPEMENT LOCAL UNIQUEMENT (`APP_ENV=local`).
 *
 * Refusé partout ailleurs. Le lien est signé, valable 10 minutes, à usage unique, et n'est accepté que depuis une adresse locale
 * (voir DevAdminLoginController). Il ne contourne que la page de connexion : rien n'existe en production.
 */
final class DevAdminLink extends Command
{
    public const MINUTES = 10;

    protected $signature = 'rendivoo:dev-admin-link {email? : E-mail du Super Admin (le premier par défaut)}';

    /** Ancien nom (produit rebaptisé Rendivoo) : gardé pour les scripts et tâches planifiées qui l'appellent encore. */
    protected $aliases = ['nova:dev-admin-link'];

    protected $description = 'Lien de connexion directe au Super Admin (développement local uniquement)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Commande réservée au développement local (APP_ENV=local) : refusée ici.');

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $admin = $email === null ? PlatformAdmin::query()->orderBy('created_at')->first() : PlatformAdmin::query()->where('email', mb_strtolower((string) $email))->first();

        if ($admin === null) {
            $this->error('Aucun Super Admin trouvé. Créez-en un : php artisan rendivoo:create-admin');

            return self::FAILURE;
        }

        $nonce = Str::random(40);
        Cache::put('dev-admin-login:'.$nonce, $admin->id, now()->addMinutes(self::MINUTES));

        $url = URL::temporarySignedRoute('dev.admin-login', now()->addMinutes(self::MINUTES), ['nonce' => $nonce]);

        $this->info("Super Admin : {$admin->email}");
        $this->line('Lien valable '.self::MINUTES.' minutes, à usage unique :');
        $this->line($url);

        return self::SUCCESS;
    }
}
