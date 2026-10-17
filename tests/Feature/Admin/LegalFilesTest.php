<?php

declare(strict_types=1);

use App\Modules\Admin\Exceptions\LegalFileRejected;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocumentDetail;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocumentHistory;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocuments;
use App\Modules\Admin\Filament\Pages\Legal\LegalDocumentUpload;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\LegalDocumentFiles;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Documents juridiques déposés en PDF (docs/35) : dépôt et remplacement, archivage sans suppression, contrôle du fichier, accès public à la
 * version en vigueur seulement, téléchargement des archives réservé au Super Admin, audit, écrans et boutons « Retour ».
 */
function legalFilesCleanup(): void
{
    // Les versions publiées sont figées : on coupe le déclencheur le temps de retirer les versions ajoutées par un test (la première reste).
    DB::connection('pgsql_owner')->unprepared("ALTER TABLE legal_document_versions DISABLE TRIGGER legal_versions_freeze; DELETE FROM legal_document_versions WHERE version <> '2026-10-01'; ALTER TABLE legal_document_versions ENABLE TRIGGER legal_versions_freeze");
}

function pdf(string $body = ''): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n{$body}\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

beforeEach(function (): void {
    legalFilesCleanup();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->boss->refresh();
    $this->agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
    $this->agent->refresh();
    $this->editor = PlatformAdmin::query()->create(['email' => 'editeur@rendivoo.test', 'password' => 'mot-de-passe-de-lediteur-2026!']);
    $this->editor->forceFill(['support_role' => PlatformAdmin::SUPPORT_EDITOR])->save();
    $this->editor->refresh();

    $this->files = fn (): LegalDocumentFiles => app(LegalDocumentFiles::class);
    $this->upload = fn (?string $content = null, string $name = 'cgu.pdf'): UploadedFile => UploadedFile::fake()->createWithContent($name, $content ?? pdf());
    $this->replace = fn (string $document = 'terms', ?UploadedFile $file = null, bool $requires = false) => ($this->files)()->replace($this->boss->id, $document, $file ?? ($this->upload)(), $requires);
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
    $this->as = fn (PlatformAdmin $admin) => $this->flushSession()->actingAs($admin, 'platform');
    $this->versions = fn (string $document = 'terms') => DB::connection('pgsql_owner')->table('legal_document_versions')->where('document', $document)->orderBy('created_at')->get();
});

afterEach(fn () => legalFilesCleanup());

describe('dépôt et remplacement', function (): void {
    it('publie le PDF comme version en vigueur et archive l\'ancienne sans la supprimer', function (): void {
        $row = ($this->replace)('terms', null, true);

        $history = ($this->files)()->history('terms');
        expect($row)->status->toBe('published')->version->toBe(now()->toDateString())->file_name->toBe('cgu.pdf')->requires_acceptance->toBeTrue()
            ->and($row->effective_on->toDateString())->toBe(now()->toDateString())->and($row->file_sha256)->toBe(hash('sha256', pdf()))->and($row->uploaded_by)->toBe($this->boss->id)
            ->and(collect($history)->pluck('state', 'version')->all())->toBe([now()->toDateString() => 'current', '2026-10-01' => 'archived'])
            ->and(($this->versions)())->toHaveCount(2);
        Storage::disk('local')->assertExists((string) $row->file_path);
        expect($row->file_path)->toMatch('#^legal/terms/[0-9a-f-]{36}\.pdf$#');
    });

    it('garde chaque version et numérote les dépôts du même jour', function (): void {
        $first = ($this->replace)();
        $second = ($this->replace)();

        expect($second->version)->toBe(now()->toDateString().'.2')->and(($this->files)()->history('terms')[0]['id'])->toBe($second->id)
            ->and(collect(($this->files)()->history('terms'))->pluck('state')->all())->toBe(['current', 'archived', 'archived']);
        Storage::disk('local')->assertExists((string) $first->file_path);
        Storage::disk('local')->assertExists((string) $second->file_path);
    });

    it('trace le dépôt, puis le remplacement avec la version précédente', function (): void {
        $first = ($this->replace)();
        $second = ($this->replace)();

        $uploaded = ($this->audit)('legal.file_uploaded')->sole();
        $replaced = ($this->audit)('legal.file_replaced')->sole();

        expect($uploaded->actor_id)->toBe($this->boss->id)->and($uploaded->entity_id)->toBe($first->id)->and(json_decode((string) $uploaded->diff, true))->toMatchArray(['document' => 'terms', 'file' => 'cgu.pdf', 'sha256' => hash('sha256', pdf())])
            ->and(json_decode((string) $replaced->diff, true))->toMatchArray(['previous_version' => $first->version, 'previous_file' => 'cgu.pdf', 'version' => $second->version]);
    });

    it('traite chacun des trois documents', function (string $document): void {
        ($this->replace)($document);

        expect(collect(($this->files)()->overview())->firstWhere('code', $document)['current']['has_file'])->toBeTrue();
    })->with(['terms', 'privacy', 'dpa']);

    it('refuse un nouveau dépôt quand une version est déjà planifiée plus tard', function (): void {
        DB::connection('pgsql_owner')->table('legal_document_versions')->insert([
            'id' => (string) Str::uuid(), 'document' => 'terms', 'version' => '2099-01-01', 'effective_on' => '2099-01-01', 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        expect(fn () => ($this->replace)())->toThrow(LegalFileRejected::class);
        expect(($this->audit)('legal.file_rejected')->sole()->diff)->toContain('future_version');
    });
});

describe('contrôle du fichier', function (): void {
    it('refuse ce qui n\'est pas un PDF, un fichier vide, trop gros ou à contenu actif, avec la raison, sans rien garder', function (string $reason, Closure $file): void {
        try {
            ($this->replace)('terms', $file($this));
            $this->fail('Le fichier aurait dû être refusé.');
        } catch (LegalFileRejected $e) {
            expect($e->reason())->toBe($reason)->and($e->message())->not->toStartWith('admin.legal_files')->not->toBe('');
        }

        expect(($this->versions)())->toHaveCount(1)->and(Storage::disk('local')->allFiles('legal'))->toBe([])
            ->and(json_decode((string) ($this->audit)('legal.file_rejected')->sole()->diff, true)['reason'])->toBe($reason);
    })->with([
        'texte renommé' => ['not_pdf', fn ($t) => ($t->upload)('Bonjour, ceci n\'est pas un PDF.', 'cgu.pdf')],
        'mauvaise extension' => ['not_pdf', fn ($t) => ($t->upload)(pdf(), 'cgu.txt')],
        'exécutable déguisé' => ['not_pdf', fn ($t) => ($t->upload)("MZ\x90\x00\x03\x00\x00\x00 programme", 'setup.pdf')],
        'script' => ['not_pdf', fn ($t) => ($t->upload)('<?php echo 1;', 'cgu.pdf')],
        'vide' => ['empty', fn ($t) => UploadedFile::fake()->createWithContent('cgu.pdf', '')],
        'trop gros' => ['too_large', fn ($t) => ($t->upload)(pdf(str_repeat('x', 20 * 1024 * 1024 + 10)))],
        'JavaScript' => ['active_content', fn ($t) => ($t->upload)(pdf("3 0 obj\n<< /S /JavaScript /JS (app.alert(1)) >>\nendobj"))],
        'lancement de programme' => ['active_content', fn ($t) => ($t->upload)(pdf("3 0 obj\n<< /S /Launch /F (cmd.exe) >>\nendobj"))],
        'fichier incorporé' => ['active_content', fn ($t) => ($t->upload)(pdf("3 0 obj\n<< /Type /EmbeddedFile >>\nendobj"))],
    ]);

    it('accepte un PDF qui contient seulement les mots d\'un texte juridique', function (): void {
        $row = ($this->replace)('terms', ($this->upload)(pdf('(Le JavaScript et les programmes sont mentionnés dans ce texte.)')));

        expect($row->hasFile())->toBeTrue();
    });

    it('accepte exactement 20 Mo', function (): void {
        $content = pdf();
        $content .= str_repeat(' ', LegalDocumentFiles::MAX_BYTES - strlen($content));

        expect(($this->replace)('terms', ($this->upload)($content))->file_size)->toBe(LegalDocumentFiles::MAX_BYTES);
    });

    it('ne garde pas le nom d\'origine pour le téléchargement et nettoie ce nom', function (): void {
        $row = ($this->replace)('terms', ($this->upload)(pdf(), '../../etc/<passwd>.pdf'));

        expect($row->file_name)->not->toContain('/')->not->toContain('..')->and(($this->files)()->downloadName($row))->toBe('terms-of-use-'.$row->version.'.pdf');
    });
});

describe('accès public', function (): void {
    it('affiche et sert le PDF en vigueur, et plus l\'ancien PDF une fois remplacé', function (): void {
        $old = ($this->replace)();
        $this->get('/legal/terms-of-use')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Legal/Show')->where('document.file.url', '/legal/terms-of-use/file')->where('document.version', $old->version)->where('document.html', ''));
        $this->get('/legal/terms-of-use/file')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->get('/legal/terms-of-use/download')->assertOk()->assertDownload('terms-of-use-'.$old->version.'.pdf');
        $this->get("/legal/terms-of-use/versions/{$old->version}")->assertOk();

        $new = ($this->replace)();

        $this->get('/legal/terms-of-use')->assertInertia(fn (Assert $page) => $page->where('document.version', $new->version));
        $this->get("/legal/terms-of-use/versions/{$old->version}")->assertNotFound();
        $this->get('/legal/terms-of-use')->assertInertia(fn (Assert $page) => $page->where('versions', fn ($versions) => collect($versions)->pluck('version')->all() === [$new->version, '2026-10-01']));
        $this->get('/legal/terms-of-use/versions/2026-10-01')->assertOk();
    });

    it('n\'expose le fichier que pour la version en vigueur d\'un PDF, jamais pour un texte', function (): void {
        $this->get('/legal/terms-of-use/file')->assertNotFound();
        $this->get('/legal/unknown/file')->assertNotFound();
        $this->get('/legal/privacy-policy/file')->assertNotFound();
    });

    it('garde le titre du document dans les pages qui le citent (inscription, réglages)', function (): void {
        ($this->replace)();

        $props = Assert::fromTestResponse($this->get('/inscription')->assertOk())->toArray()['props'];

        expect(collect($props['legal']['documents'])->firstWhere('code', 'terms')['title'])->toBe(__('ui.legal.documents.terms'));
    });
});

describe('téléchargement par le Super Admin', function (): void {
    it('permet de télécharger la version en vigueur et les archives, et le trace', function (): void {
        $old = ($this->replace)();
        $new = ($this->replace)();

        $url = fn ($row) => route('filament.admin.legal.file', ['document' => 'terms', 'version' => $row->id]);

        ($this->as)($this->boss)->get($url($old))->assertOk()->assertDownload('terms-of-use-'.$old->version.'.pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        ($this->as)($this->boss)->get($url($new))->assertOk();

        $downloads = ($this->audit)('legal.file_downloaded');
        expect($downloads)->toHaveCount(2)->and($downloads->map(fn ($row) => json_decode((string) $row->diff, true)['archived'])->all())->toBe([true, false]);
    });

    it('refuse les visiteurs, les clients, les agents de support et les éditeurs', function (): void {
        $row = ($this->replace)();
        $url = route('filament.admin.legal.file', ['document' => 'terms', 'version' => $row->id]);

        $this->get($url)->assertRedirect('/admin/login');
        ($this->as)($this->agent)->get($url)->assertForbidden();
        ($this->as)($this->editor)->get($url)->assertForbidden();
        expect(($this->audit)('legal.file_downloaded'))->toHaveCount(0);
    });

    it('répond 404 pour une version sans fichier ou d\'un autre document', function (): void {
        $text = DB::connection('pgsql_owner')->table('legal_document_versions')->where('document', 'terms')->value('id');
        $row = ($this->replace)('privacy');

        ($this->as)($this->boss)->get(route('filament.admin.legal.file', ['document' => 'terms', 'version' => $text]))->assertNotFound();
        ($this->as)($this->boss)->get(route('filament.admin.legal.file', ['document' => 'terms', 'version' => $row->id]))->assertNotFound();
    });
});

describe('écrans', function (): void {
    it('affiche la liste des trois documents avec « Retour » et la version en vigueur', function (): void {
        $row = ($this->replace)('dpa');

        Livewire::actingAs($this->boss, 'platform')->test(LegalDocuments::class)->assertSuccessful()
            ->assertSee(__('admin.legal_files.back.dashboard'))->assertSee(__('ui.legal.documents.terms'))->assertSee(__('ui.legal.documents.dpa'))->assertSee('cgu.pdf')->assertSee($row->version)
            ->assertSee(__('admin.legal_files.actions.upload'));
    });

    it('affiche la fiche d\'un document : version en vigueur, versions récentes, « Retour aux documents juridiques »', function (): void {
        ($this->replace)();

        Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'terms'])->test(LegalDocumentDetail::class)->assertSuccessful()
            ->assertSee(__('admin.legal_files.back.legal'))->assertSee(__('admin.legal_files.states.current'))->assertSee(__('admin.legal_files.states.archived'))
            ->assertSee(__('admin.legal_files.actions.download'))->assertSee('cgu.pdf')->assertSee(__('admin.legal_files.actions.upload'));
    });

    it('affiche l\'historique complet, avec le statut de chaque version et « Retour à [document] »', function (): void {
        ($this->replace)();
        ($this->replace)();

        Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'terms'])->test(LegalDocumentHistory::class)->assertSuccessful()
            ->assertSee(__('admin.legal_files.back.document', ['name' => __('ui.legal.documents.terms')]))->assertSee(__('admin.legal_files.archived_note'))
            ->assertSee(now()->toDateString().'.2')->assertSee('2026-10-01')->assertSee(__('admin.legal_files.text_version'));
    });

    it('refuse un code de document inconnu et les rôles restreints', function (): void {
        Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'nope'])->test(LegalDocumentUpload::class)->assertNotFound();

        foreach ([$this->agent, $this->editor] as $restricted) {
            ($this->as)($restricted);
            expect(LegalDocuments::canAccess())->toBeFalse()->and(LegalDocumentUpload::canAccess())->toBeFalse()->and(LegalDocumentHistory::canAccess())->toBeFalse();
        }
    });
});

describe('page de dépôt', function (): void {
    it('remplace le document après confirmation, affiche le succès et revient à la fiche', function (): void {
        $test = Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'terms'])->test(LegalDocumentUpload::class)
            ->assertSee(__('admin.legal_files.back.document', ['name' => __('ui.legal.documents.terms')]))->assertSee(__('admin.legal_files.cancel'))
            ->fillForm(['file' => ($this->upload)()])
            ->callAction('replace');

        $test->assertHasNoFormErrors()->assertRedirect(LegalDocumentDetail::getUrl(['document' => 'terms']))->assertNotified(__('admin.legal_files.success', ['name' => __('ui.legal.documents.terms')]));
        expect(($this->versions)())->toHaveCount(2)->and(($this->files)()->overview()[0]['current']['has_file'])->toBeTrue();
    });

    it('demande la confirmation « la version précédente sera archivée » avant de remplacer', function (): void {
        Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'terms'])->test(LegalDocumentUpload::class)
            ->fillForm(['file' => ($this->upload)()])
            ->mountAction('replace')->assertActionMounted('replace');

        expect(__('admin.legal_files.confirm.body', ['name' => __('ui.legal.documents.terms')]))->toContain(__('ui.legal.documents.terms'));

        expect(($this->versions)())->toHaveCount(1);
    });

    it('refuse un fichier invalide avec un message clair, sans rien changer', function (): void {
        Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'terms'])->test(LegalDocumentUpload::class)
            ->fillForm(['file' => ($this->upload)('pas un pdf')])
            ->callAction('replace')
            ->assertHasErrors(['data.file']);

        expect(($this->versions)())->toHaveCount(1)->and(($this->audit)('legal.file_rejected'))->toHaveCount(1);
    });

    it('exige un fichier', function (): void {
        Livewire::actingAs($this->boss, 'platform')->withQueryParams(['document' => 'terms'])->test(LegalDocumentUpload::class)->call('submit')->assertHasErrors(['data.file']);

        expect(($this->versions)())->toHaveCount(1);
    });
});
