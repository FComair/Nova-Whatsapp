<?php

declare(strict_types=1);

use App\Console\Commands\Preflight;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Support\Services\AttachmentStore;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Corrections issues de l'audit RGPD (docs/37) : connexions du Super Admin tracées, expiration après inactivité, minimisation planifiée,
 * contrôle de mise en production des liens chiffrés.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->admin = $this->admin->refresh();
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
});

describe('connexions du Super Admin', function (): void {
    it('trace une connexion réussie et met à jour la dernière connexion', function (): void {
        expect($this->admin->last_login_at)->toBeNull();

        event(new Login('platform', $this->admin, false));

        $row = ($this->audit)('platform.login')->sole();
        expect($row->actor_id)->toBe($this->admin->id)->and($row->actor_type)->toBe('platform_admin')->and($this->admin->refresh()->last_login_at)->not->toBeNull();
    });

    it('trace un échec sur un compte connu, rattaché au compte', function (): void {
        event(new Failed('platform', $this->admin, ['email' => 'fondateur@nova.test', 'password' => 'mauvais']));

        $row = ($this->audit)('platform.login_failed')->sole();
        expect($row->actor_id)->toBe($this->admin->id)->and(json_encode($row))->not->toContain('mauvais');
    });

    it('trace un échec sur un compte inconnu sans garder l\'adresse saisie ni le mot de passe', function (): void {
        event(new Failed('platform', null, ['email' => 'Inconnu@Example.com', 'password' => 'secret-saisi']));

        $row = ($this->audit)('platform.login_failed')->sole();
        $payload = json_encode($row);

        expect($row->actor_type)->toBe('system')->and($payload)->toContain(hash('sha256', 'inconnu@example.com'))->not->toContain('nconnu@')->not->toContain('secret-saisi');
    });

    it('ignore les connexions de l\'espace client', function (): void {
        event(new Failed('web', null, ['email' => 'client@example.com', 'password' => 'x']));
        event(new Login('web', $this->admin, false));

        expect(($this->audit)('platform.login_failed'))->toHaveCount(0)->and(($this->audit)('platform.login'))->toHaveCount(0);
    });
});

describe('expiration après inactivité', function (): void {
    it('laisse passer une session récente et repousse l\'échéance', function (): void {
        config(['nova.security.admin_idle_minutes' => 30]);

        $this->withSession(['admin_last_activity' => now()->subMinutes(10)->timestamp])->actingAs($this->admin, 'platform')->get('/admin/organizations')->assertOk();

        expect(session('admin_last_activity'))->toBeGreaterThan(now()->subMinute()->timestamp);
    });

    it('déconnecte après la durée d\'inactivité, trace l\'expiration et renvoie vers la connexion', function (): void {
        config(['nova.security.admin_idle_minutes' => 30]);

        $this->withSession(['admin_last_activity' => now()->subMinutes(45)->timestamp])->actingAs($this->admin, 'platform')->get('/admin/organizations')->assertRedirect(route('filament.admin.auth.login'));

        expect(($this->audit)('platform.session_expired')->sole()->actor_id)->toBe($this->admin->id)->and(auth('platform')->check())->toBeFalse();
    });

    it('répond « page expirée » à une requête Livewire expirée', function (): void {
        config(['nova.security.admin_idle_minutes' => 30]);

        $this->withSession(['admin_last_activity' => now()->subMinutes(45)->timestamp])->actingAs($this->admin, 'platform')->withHeader('X-Livewire', 'true')->get('/admin/organizations')->assertStatus(419);
    });

    it('peut être désactivée', function (): void {
        config(['nova.security.admin_idle_minutes' => 0]);

        $this->withSession(['admin_last_activity' => now()->subDays(2)->timestamp])->actingAs($this->admin, 'platform')->get('/admin/organizations')->assertOk();
    });
});

describe('minimisation et configuration', function (): void {
    it('planifie la purge des tâches échouées et des jetons de réinitialisation expirés', function (): void {
        $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command)->implode("\n");

        expect($commands)->toContain('queue:prune-failed --hours=168')->toContain('auth:clear-resets');
    });

    it('refuse en production une adresse publique, un n8n ou un fournisseur d\'IA sans HTTPS', function (): void {
        config(['app.url' => 'http://nova.example.eu', 'whatsapp.n8n.url' => 'http://n8n.example.eu/hook', 'conversation.ai.api_key' => 'cle', 'conversation.ai.base_url' => 'http://ia.example.eu/v1']);
        $failed = collect(app(Preflight::class)->checks())->where(0, 'error')->reject(fn (array $check): bool => $check[2])->pluck(1)->all();

        expect($failed)->toContain('Adresse publique en HTTPS')->toContain('Transfert vers n8n en HTTPS')->toContain('Assistant IA : fournisseur en HTTPS');
    });

    it('avertit tant que l\'assistant IA est actif, pour déclarer le sous-traitant', function (): void {
        config(['conversation.ai.api_key' => 'cle']);
        $warnings = collect(app(Preflight::class)->checks())->where(0, 'warning')->reject(fn (array $check): bool => $check[2])->pluck(1)->all();

        expect($warnings)->toContain('Assistant IA désactivé, ou fournisseur déclaré dans le DPA et la politique de confidentialité');
    });
});

describe('pièces jointes PDF', function (): void {
    it('refuse un PDF qui exécute du code, lance un programme ou embarque un fichier', function (string $marker): void {
        $file = UploadedFile::fake()->createWithContent('facture.pdf', "%PDF-1.4\n1 0 obj\n<< {$marker} >>\nendobj\n%%EOF");

        expect(fn () => app(AttachmentStore::class)->store($file, 'org', 'ticket'))->toThrow(InvalidArgumentException::class);
        expect(Storage::disk('local')->allFiles('support'))->toBe([]);
    })->with(['/S /JavaScript /JS (app.alert(1))', '/S /Launch /F (cmd.exe)', '/Type /EmbeddedFile']);

    it('accepte un PDF ordinaire, même s\'il parle de JavaScript dans son texte', function (): void {
        $file = UploadedFile::fake()->createWithContent('facture.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n(Le JavaScript est un langage.)\n%%EOF");

        $stored = app(AttachmentStore::class)->store($file, 'org', 'ticket');

        expect($stored['mime'])->toBe('application/pdf');
        Storage::disk('local')->assertExists($stored['path']);
    });
});
