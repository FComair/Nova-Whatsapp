<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Audit\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crée un compte Super Admin. Aucun mot de passe en argument (il finirait dans l'historique du shell) :
 * il est saisi de façon masquée. Le 2FA est configuré à la première connexion (obligatoire).
 */
final class CreatePlatformAdmin extends Command
{
    protected $signature = 'nova:create-admin {email : Adresse e-mail du compte} {--support-agent : Agent de support : accès limité aux tickets (jamais aux comptes, à la facturation ni aux réglages)} {--content-editor : Éditeur de contenu : accès limité aux FAQ et à leurs catégories}';

    protected $description = 'Crée un compte Super Admin, un agent de support (--support-agent) ou un éditeur de contenu (--content-editor) ; le 2FA est imposé à la première connexion';

    public function handle(): int
    {
        if ($this->option('support-agent') && $this->option('content-editor')) {
            $this->error('--support-agent et --content-editor sont exclusifs : choisissez un seul rôle.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));

        $password = (string) $this->secret('Mot de passe (16 caractères minimum)');
        $confirmation = (string) $this->secret('Confirmez le mot de passe');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'email:rfc', 'unique:pgsql_platform.platform_admins,email'],
                'password' => ['required', 'confirmed', Password::min(16)->letters()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = PlatformAdmin::query()->create(['email' => $email, 'password' => $password]);

        if ($this->option('support-agent')) {
            $admin->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
        } elseif ($this->option('content-editor')) {
            $admin->forceFill(['support_role' => PlatformAdmin::SUPPORT_EDITOR])->save();
        }

        Audit::record('platform_admin.created', 'system', entityType: 'platform_admin', entityId: $admin->id, diff: ['support_role' => $admin->support_role]);

        $this->info(($admin->isSupportAgent() ? 'Agent de support créé : ' : ($admin->isContentEditor() ? 'Éditeur de contenu créé : ' : 'Super Admin créé : ')).$email);
        $this->line('À la première connexion, configurez l\'application d\'authentification (2FA obligatoire) et conservez les codes de secours.');

        return self::SUCCESS;
    }
}
