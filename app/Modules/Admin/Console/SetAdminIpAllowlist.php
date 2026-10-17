<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console;

use App\Modules\Admin\Models\PlatformAdmin;
use Illuminate\Console\Command;

/**
 * Définit les adresses IP autorisées d'un Super Admin. Sans `--ip` : la restriction est levée.
 * Attention : renseignez d'abord VOTRE adresse, sinon vous vous verrouillez hors du panel.
 */
final class SetAdminIpAllowlist extends Command
{
    protected $signature = 'rendivoo:admin-allowlist {email : E-mail du Super Admin} {--ip=* : Adresse ou plage CIDR autorisée (répétable)}';

    /** Ancien nom (produit rebaptisé Rendivoo) : gardé pour les scripts et tâches planifiées qui l'appellent encore. */
    protected $aliases = ['nova:admin-allowlist'];

    protected $description = 'Restreint un Super Admin à des adresses IP';

    public function handle(): int
    {
        $admin = PlatformAdmin::query()->where('email', (string) $this->argument('email'))->first();

        if ($admin === null) {
            $this->error('Super Admin introuvable.');

            return self::FAILURE;
        }

        /** @var list<string> $ips */
        $ips = array_values(array_unique(array_map('trim', (array) $this->option('ip'))));

        foreach ($ips as $ip) {
            if (filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP) === false) {
                $this->error("Adresse invalide : {$ip}");

                return self::FAILURE;
            }
        }

        $admin->forceFill(['ip_allowlist' => $ips === [] ? null : $ips])->save();
        $this->info($ips === [] ? 'Restriction levée.' : count($ips).' adresse(s) autorisée(s).');

        return self::SUCCESS;
    }
}
