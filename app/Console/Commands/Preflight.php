<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Admin\Models\PlatformAdmin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Contrôle de mise en production : vérifie que la configuration n'a plus rien de « développement ».
 * À lancer avant chaque déploiement (`php artisan nova:preflight`) ; code de sortie 1 s'il reste une ERREUR (avertissements tolérés).
 */
final class Preflight extends Command
{
    protected $signature = 'nova:preflight';

    protected $description = 'Vérifie la configuration avant une mise en production';

    public function handle(): int
    {
        $errors = 0;

        foreach ($this->checks() as [$level, $label, $ok, $hint]) {
            if ($ok) {
                $this->line("  <fg=green>OK</>       {$label}");

                continue;
            }

            $level === 'error' ? $errors++ : null;
            $this->line($level === 'error' ? "  <fg=red>ERREUR</>   {$label} : {$hint}" : "  <fg=yellow>ATTENTION</> {$label} : {$hint}");
        }

        $this->newLine();
        $errors === 0 ? $this->info('Aucune erreur bloquante.') : $this->error("{$errors} erreur(s) à corriger avant la mise en production.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<array{0: 'error'|'warning', 1: string, 2: bool, 3: string}>
     */
    public function checks(): array
    {
        $role = DB::selectOne('select (rolsuper or rolbypassrls) as privileged from pg_roles where rolname = current_user');
        $admin = PlatformAdmin::query()->first();
        $filled = fn (string $key): bool => is_string(config($key)) && trim((string) config($key)) !== '';

        return [
            ['error', 'Environnement de production', app()->isProduction(), 'APP_ENV doit valoir production'],
            ['error', 'Adresse publique en HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'APP_URL doit commencer par https:// (liens des e-mails, cookies sécurisés, HSTS)'],
            ['error', 'Mode débogage coupé', config('app.debug') === false, 'APP_DEBUG=false'],
            ['error', 'Clé d\'application définie', $filled('app.key'), 'APP_KEY manquante'],
            ['error', 'Double authentification obligatoire (propriétaire et responsables)', config('nova.security.two_factor_required') === true, 'NOVA_REQUIRE_2FA=true (ne se désactive qu\'en développement local)'],
            ['error', 'Cookies de session sécurisés', config('session.secure') === true, 'SESSION_SECURE_COOKIE=true'],
            ['error', 'Sessions chiffrées', config('session.encrypt') === true, 'SESSION_ENCRYPT=true'],
            ['error', 'Le rôle de base de données n\'est ni superutilisateur ni BYPASSRLS', ! (bool) ($role->privileged ?? true), 'DB_USERNAME doit être nova_app (la RLS ne s\'applique pas à un rôle privilégié)'],
            ['error', 'Mot de passe de base personnalisé', config('database.connections.pgsql.password') !== 'nova_app_dev_only', 'DB_PASSWORD est encore la valeur de développement'],
            ['error', 'Code de vérification téléphone : pas de pilote « log »', config('nova.phone_code_driver') !== 'log', 'NOVA_PHONE_CODE_DRIVER : brancher un vrai fournisseur SMS'],
            ['error', 'Paiement : pas de prestataire simulé', config('nova.billing.gateway') !== 'fake', 'BILLING_GATEWAY : choisir un prestataire'],
            ['error', 'WhatsApp : pilote « meta »', config('whatsapp.driver') === 'meta', 'WHATSAPP_DRIVER=meta'],
            ['error', 'WhatsApp : secret d\'application et jeton de vérification', $filled('whatsapp.app_secret') && $filled('whatsapp.verify_token') && config('whatsapp.verify_token') !== 'dev-verify-token' && config('whatsapp.app_secret') !== 'dev-app-secret', 'WHATSAPP_APP_SECRET / WHATSAPP_VERIFY_TOKEN'],
            ['error', 'E-mail : pas de pilote de développement', ! in_array(config('mail.default'), ['log', 'array'], true) && config('mail.mailers.smtp.host') !== 'mailpit', 'MAIL_MAILER / MAIL_HOST'],
            ['error', 'Identité du vendeur sur les factures', $filled('nova.billing.seller.name') && ! str_contains((string) config('nova.billing.seller.name'), 'à renseigner') && $filled('nova.billing.seller.vat_number') && $filled('nova.billing.seller.address'), 'BILLING_SELLER_NAME / _VAT / _ADDRESS'],
            ['error', 'Alerte fondateur (suppression bloquée, E12)', $filled('nova.founder_email'), 'NOVA_FOUNDER_EMAIL'],
            ['error', 'Transfert vers n8n en HTTPS', ! $filled('whatsapp.n8n.url') || str_starts_with((string) config('whatsapp.n8n.url'), 'https://'), 'N8N_INBOUND_URL : les messages des clients finaux ne doivent jamais circuler en clair'],
            ['error', 'Assistant IA : fournisseur en HTTPS', ! $filled('conversation.ai.api_key') || str_starts_with((string) config('conversation.ai.base_url'), 'https://'), 'CONVERSATION_AI_BASE_URL doit commencer par https://'],
            ['warning', 'Assistant IA désactivé, ou fournisseur déclaré dans le DPA et la politique de confidentialité', ! $filled('conversation.ai.api_key'), 'Le texte des messages des clients finaux part chez le fournisseur d\'IA : sous-traitant à déclarer, transfert hors UE à encadrer (docs/37)'],
            ['warning', 'Vérification de l\'e-mail et 2FA (clients, Super Admin) toutes exigées', collect(PlatformSettings::FLAGS)->every(fn (string $key): bool => app(PlatformSettings::class)->enabled($key)), 'Paramètres globaux du Super Admin : réactiver dès qu\'un serveur d\'e-mail est en place'],
            ['warning', 'Session Super Admin : expiration après inactivité', (int) config('nova.security.admin_idle_minutes') > 0, 'NOVA_ADMIN_IDLE_MINUTES > 0'],
            ['warning', 'Inscription intégrée de Meta (Embedded Signup) configurée', $filled('whatsapp.embedded_signup.app_id') && $filled('whatsapp.embedded_signup.config_id'), 'META_APP_ID / META_EMBEDDED_SIGNUP_CONFIG_ID (docs/17) ; sinon seule la connexion manuelle est proposée'],
            ['warning', 'Transfert vers n8n configuré', $filled('whatsapp.n8n.url') && $filled('whatsapp.n8n.signing_secret') && config('whatsapp.n8n.signing_secret') !== 'dev-n8n-secret', 'N8N_INBOUND_URL / N8N_SIGNING_SECRET'],
            ['warning', 'Proxys de confiance (adresses IP réelles dans le journal d\'audit)', $filled('nova.trusted_proxies'), 'TRUSTED_PROXIES, sinon toutes les IP seront celles du proxy'],
            ['warning', 'Panel Super Admin sur un sous-domaine dédié', $filled('nova.admin_domain'), 'NOVA_ADMIN_DOMAIN (cookies de session distincts)'],
            ['warning', 'Politique de contenu (CSP) appliquée', config('nova.security.csp') === 'enforce', 'NOVA_CSP=enforce, après une période en report-only'],
            ['warning', 'Niveau de journal', ! in_array(config('logging.channels.'.config('logging.default').'.level', 'debug'), ['debug', 'info'], true), 'LOG_LEVEL=warning (les traces d\'erreur SQL peuvent citer des données)'],
            ['warning', 'Connexion PostgreSQL chiffrée (TLS)', in_array(config('database.connections.pgsql.sslmode'), ['require', 'verify-ca', 'verify-full'], true), 'DB_SSLMODE=verify-full (ou require) : les données personnelles ne doivent pas circuler en clair entre l\'application et la base'],
            ['warning', 'Mot de passe Redis', $filled('database.redis.default.password'), 'REDIS_PASSWORD'],
            ['warning', 'Super Admin : 2FA configuré et adresses IP restreintes', $admin !== null && $admin->getAppAuthenticationSecret() !== null && ! empty($admin->ip_allowlist), 'nova:create-admin, puis nova:admin-allowlist'],
        ];
    }
}
