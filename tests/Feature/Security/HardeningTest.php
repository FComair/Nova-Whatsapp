<?php

declare(strict_types=1);

use App\Console\Commands\Preflight;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Tenancy\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Durcissement issu de la relecture de sécurité : en-têtes HTTP, liste d'adresses IP du Super Admin, droits de la base,
 * contrôle de mise en production.
 */
describe('en-têtes de sécurité', function (): void {
    it('ajoute les en-têtes de base à toute réponse, pages comme API', function (string $uri): void {
        $response = $this->get($uri);

        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
            ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')
            ->and($response->headers->get('Cross-Origin-Opener-Policy'))->toBe('same-origin');
    })->with(['/connexion', '/api/v1/config', '/up']);

    it('publie la politique de contenu en mode « signalement » par défaut', function (): void {
        $response = $this->get('/connexion');

        expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")
            ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
    });

    it('la fait respecter en mode « enforce » et peut être coupée', function (): void {
        config(['nova.security.csp' => 'enforce']);
        $enforced = $this->get('/connexion');

        expect($enforced->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")->and($enforced->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();

        config(['nova.security.csp' => 'off']);
        $off = $this->get('/connexion');

        expect($off->headers->has('Content-Security-Policy'))->toBeFalse()->and($off->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
    });

    it('n\'applique pas la politique de contenu au panel Super Admin, mais garde les autres en-têtes', function (): void {
        $response = $this->get('/admin/login');

        expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
    });

    it('n\'ajoute la politique de contenu qu\'aux pages HTML', function (): void {
        expect($this->getJson('/api/v1/config')->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
    });

    it('impose HTTPS (HSTS) en production sur les requêtes sécurisées seulement', function (): void {
        $this->app['env'] = 'production';

        $secure = $this->get('https://localhost/connexion');
        $plain = $this->get('http://localhost/connexion');

        expect($secure->headers->get('Strict-Transport-Security'))->toContain('max-age=31536000')->and($plain->headers->has('Strict-Transport-Security'))->toBeFalse();
    });

    it('ne l\'envoie pas hors production', function (): void {
        expect($this->get('https://localhost/connexion')->headers->has('Strict-Transport-Security'))->toBeFalse();
    });
});

describe('liste d\'adresses IP du Super Admin', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->admin->refresh();
        $this->from = fn (string $ip) => $this->withServerVariables(['REMOTE_ADDR' => $ip])->actingAs($this->admin, 'platform')->get('/admin');
        $this->allow = fn (?array $ips) => $this->admin->forceFill(['ip_allowlist' => $ips])->save();
    });

    it('ne restreint rien quand la liste est vide', function (): void {
        ($this->from)('198.51.100.7')->assertOk();
    });

    it('autorise une adresse ou une plage listée (IPv4 et IPv6)', function (string $ip): void {
        ($this->allow)(['203.0.113.0/24', '2001:db8::/32', '192.0.2.10']);

        ($this->from)($ip)->assertOk();
    })->with(['203.0.113.77', '192.0.2.10', '2001:db8:abcd::1']);

    it('refuse, ferme la session et trace une adresse hors liste', function (string $ip): void {
        ($this->allow)(['203.0.113.0/24']);

        ($this->from)($ip)->assertForbidden();

        expect(DB::connection('pgsql_owner')->table('audit_logs')->where('action', 'platform.ip_denied')->where('actor_id', $this->admin->id)->count())->toBe(1);
    })->with(['198.51.100.7', '203.0.114.1', '2001:db9::1']);

    it('se règle par commande, vérifie les adresses et peut être levée', function (): void {
        $this->artisan('nova:admin-allowlist', ['email' => 'fondateur@nova.test', '--ip' => ['203.0.113.5', '10.0.0.0/8']])->expectsOutputToContain('2 adresse(s) autorisée(s).')->assertSuccessful();
        expect($this->admin->refresh()->ip_allowlist)->toBe(['203.0.113.5', '10.0.0.0/8']);

        $this->artisan('nova:admin-allowlist', ['email' => 'fondateur@nova.test', '--ip' => ['pas-une-ip']])->assertFailed();
        $this->artisan('nova:admin-allowlist', ['email' => 'inconnu@nova.test'])->assertFailed();
        expect($this->admin->refresh()->ip_allowlist)->toBe(['203.0.113.5', '10.0.0.0/8']);

        $this->artisan('nova:admin-allowlist', ['email' => 'fondateur@nova.test'])->expectsOutputToContain('Restriction levée.')->assertSuccessful();
        expect($this->admin->refresh()->ip_allowlist)->toBeNull();
    });
});

describe('droits de la base', function (): void {
    it('interdit au rôle client de supprimer une organisation ou de lire l\'historique des migrations', function (): void {
        ['org' => $org] = $this->activatedAccount();

        expect(fn () => app(TenantContext::class)->run($org->id, fn () => DB::table('organizations')->where('id', $org->id)->delete()))->toThrow(QueryException::class)
            ->and(fn () => DB::table('migrations')->count())->toThrow(QueryException::class);
    });

    it('interdit au rôle client d\'écrire dans les paramètres globaux, les plans et les tables de suppression', function (): void {
        foreach (['platform_settings', 'plans', 'deletion_requests', 'deletion_jobs', 'invoice_sequences'] as $table) {
            expect(fn () => DB::table($table)->delete())->toThrow(QueryException::class, '', 0);
        }
    });
});

describe('contrôle de mise en production', function (): void {
    it('échoue tant que la configuration est celle du développement et liste les points à corriger', function (): void {
        expect(Artisan::call('nova:preflight'))->toBe(1)->and(Artisan::output())->toContain('ERREUR')->toContain('APP_ENV doit valoir production')->toContain('BILLING_GATEWAY');
    });

    it('refuse une production où la double authentification obligatoire est désactivée', function (): void {
        config(['nova.security.two_factor_required' => false]);

        expect(Artisan::call('nova:preflight'))->toBe(1)->and(Artisan::output())->toContain('Double authentification obligatoire')->toContain('NOVA_REQUIRE_2FA');
    });

    it('distingue erreurs bloquantes et avertissements', function (): void {
        $checks = collect(app(Preflight::class)->checks());

        expect($checks->pluck(0)->unique()->sort()->values()->all())->toBe(['error', 'warning'])
            ->and($checks->where(0, 'error')->count())->toBeGreaterThan(8);
    });

    it('valide une configuration de production complète', function (): void {
        config([
            'app.debug' => false, 'nova.security.two_factor_required' => true, 'session.secure' => true, 'session.encrypt' => true, 'nova.phone_code_driver' => 'sms', 'nova.billing.gateway' => 'mollie',
            'whatsapp.driver' => 'meta', 'whatsapp.app_secret' => 'secret-reel', 'whatsapp.verify_token' => 'jeton-reel', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.eu',
            'nova.billing.seller' => ['name' => 'Nova SRL', 'vat_number' => 'BE0123456789', 'address' => 'Rue 1, 1000 Bruxelles'], 'nova.founder_email' => 'fondateur@nova.test',
            'database.connections.pgsql.password' => 'mot-de-passe-de-production', 'app.url' => 'https://nova.example.eu',
        ]);
        $this->app['env'] = 'production';

        $failed = collect(app(Preflight::class)->checks())->where(0, 'error')->reject(fn (array $check): bool => $check[2])->pluck(1)->all();

        // Seule la règle sur le rôle privilégié peut encore échouer : la base de test se connecte avec le rôle applicatif non privilégié.
        expect($failed)->toBe([]);
    });
});
