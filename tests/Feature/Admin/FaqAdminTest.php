<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\FaqArticleResource;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\CreateFaqArticle;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\EditFaqArticle;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\ListFaqArticles;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\PreviewFaqArticle;
use App\Modules\Admin\Filament\Resources\FaqCategoryResource\Pages\ManageFaqCategories;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Services\FaqCategoryManager;
use App\Modules\Admin\Services\FaqManager;
use App\Modules\Help\Models\FaqVersion;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin > FAQ (docs/30) : liste (vues, recherche, filtres, tri, actions, actions en lot), éditeur, aperçu, catégories, droits.
 */
function faqReady(string $question, string $body = "## Étapes\n\nOuvrez **Réglages**."): array
{
    return ['question' => $question, 'summary' => 'Un résumé assez long pour les listes.', 'body' => $body, 'is_ready' => true];
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->editor = PlatformAdmin::query()->create(['email' => 'editeur@rendivoo.test', 'password' => 'mot-de-passe-de-lediteur-2026!']);
    $this->editor->forceFill(['support_role' => PlatformAdmin::SUPPORT_EDITOR])->save();
    $this->agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
    foreach (['boss', 'editor', 'agent'] as $name) {
        $this->{$name}->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->{$name} = $this->{$name}->refresh();
    }

    $this->as = fn (PlatformAdmin $admin) => $this->flushSession()->actingAs($admin, 'platform');
    $this->manager = fn (): FaqManager => app(FaqManager::class);
    $this->category = fn (string $slug = 'novelties', string $fr = 'Nouveautés'): PlatformFaqCategory => PlatformFaqCategory::query()->where('slug', $slug)->first()
        ?? app(FaqCategoryManager::class)->create($this->boss, ['slug' => $slug, 'names' => ['fr' => $fr, 'en' => 'News'], 'icon' => 'chat', 'position' => 5]);
    $this->faq = fn (string $question, array $overrides = []): PlatformFaqArticle => ($this->manager)()->create($this->boss, [
        'category_id' => ($this->category)()->id,
        'translations' => ['fr' => faqReady($question), 'en' => faqReady('English: '.$question, 'English body.')],
        ...$overrides,
    ]);
    $this->fresh = fn (PlatformFaqArticle $article): PlatformFaqArticle => PlatformFaqArticle::query()->findOrFail($article->id);
    $this->list = fn (?PlatformAdmin $admin = null) => Livewire::actingAs($admin ?? $this->boss, 'platform')->test(ListFaqArticles::class);
    $this->records = function (array $articles) {
        $ids = collect($articles)->pluck('id')->all();

        return PlatformFaqArticle::query()->whereIn('id', $ids)->get()->sortBy(fn (PlatformFaqArticle $a) => array_search($a->id, $ids, true))->values();
    };
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
});

describe('accès', function (): void {
    it('réserve les écrans FAQ aux comptes de la plateforme', function (): void {
        $this->get('/admin/faq')->assertRedirect('/admin/login');
        $this->actingAs($this->owner, 'web')->get('/admin/faq')->assertRedirect('/admin/login');
        ($this->as)($this->boss)->get('/admin/faq')->assertOk();
        ($this->as)($this->boss)->get('/admin/faq-categories')->assertOk();
        ($this->as)($this->boss)->get('/admin/faq/create')->assertOk();
    });

    it('ouvre les FAQ à l\'éditeur de contenu seulement, qui arrive directement dessus', function (): void {
        ($this->as)($this->editor)->get('/admin/faq')->assertOk();
        ($this->as)($this->editor)->get('/admin/faq-categories')->assertOk();
        ($this->as)($this->editor)->get('/admin')->assertRedirect(FaqArticleResource::getUrl());
        foreach (['/admin/support-tickets', '/admin/support-reports', '/admin/organizations', '/admin/invoices', '/admin/platform-settings'] as $url) {
            ($this->as)($this->editor)->get($url)->assertForbidden();
        }
    });

    it('ferme les FAQ à l\'agent de support', function (): void {
        ($this->as)($this->agent)->get('/admin/faq')->assertForbidden();
        ($this->as)($this->agent)->get('/admin/faq-categories')->assertForbidden();
        ($this->as)($this->agent)->get('/admin/support-tickets')->assertOk();
    });

    it('crée un éditeur de contenu avec --content-editor et refuse deux rôles à la fois', function (): void {
        $this->artisan('rendivoo:create-admin', ['email' => 'nouvel.editeur@rendivoo.test', '--content-editor' => true])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', 'Un-Mot-De-Passe-Solide-2026!')
            ->expectsQuestion('Confirmez le mot de passe', 'Un-Mot-De-Passe-Solide-2026!')
            ->assertSuccessful();

        expect(PlatformAdmin::query()->where('email', 'nouvel.editeur@rendivoo.test')->first())->support_role->toBe('editor');
        $this->artisan('rendivoo:create-admin', ['email' => 'double@rendivoo.test', '--content-editor' => true, '--support-agent' => true])->assertFailed();
        expect(PlatformAdmin::query()->where('email', 'double@rendivoo.test')->exists())->toBeFalse();
    });
});

describe('liste', function (): void {
    it('affiche question, catégorie, état, état de chaque langue, dernier éditeur et ordre', function (): void {
        $article = ($this->faq)('Comment inviter un collègue ?', ['position' => 7]);

        ($this->list)()->assertSuccessful()->assertCanSeeTableRecords(($this->records)([$article]))
            ->assertTableColumnStateSet('title', 'Comment inviter un collègue ?', $article->id)
            ->assertTableColumnStateSet('category_name', 'Nouveautés', $article->id)
            ->assertTableColumnStateSet('status', 'draft', $article->id)
            ->assertTableColumnStateSet('lang_fr', 'ready', $article->id)
            ->assertTableColumnStateSet('lang_nl', 'missing', $article->id)
            ->assertTableColumnStateSet('lang_en', 'ready', $article->id)
            ->assertTableColumnStateSet('editor_email', 'fondateur@rendivoo.test', $article->id)
            ->assertTableColumnStateSet('position', 7, $article->id);
    });

    it('propose les vues par état avec leur compteur', function (): void {
        $draft = ($this->faq)('Un brouillon');
        $published = ($this->faq)('Une FAQ publiée');
        $archived = ($this->faq)('Une FAQ archivée');
        ($this->manager)()->publish($this->boss, $published);
        ($this->manager)()->archive($this->boss, $archived);

        $sees = fn (string $tab, array $see, array $hide) => ($this->list)()->set('activeTab', $tab)->assertCanSeeTableRecords(($this->records)($see))->assertCanNotSeeTableRecords(($this->records)($hide));
        $sees('active', [$draft, $published], [$archived]);
        $sees('published', [$published], [$draft, $archived]);
        $sees('draft', [$draft], [$published, $archived]);
        $sees('archived', [$archived], [$draft, $published]);
        $sees('all', [$draft, $published, $archived], []);

        $tabs = ($this->list)()->instance()->getTabs();
        expect((int) $tabs['active']->getBadge())->toBe(2)->and((int) $tabs['all']->getBadge())->toBe(3)->and((int) $tabs['archived']->getBadge())->toBe(1);
    });

    it('cherche dans la question, le texte, le résumé, les étiquettes, la catégorie et l\'adresse', function (): void {
        $a = ($this->faq)('Facture en double', ['slug' => 'facture-double', 'tags' => ['remboursement']]);
        ($this->manager)()->update($this->boss, $a, ['translations' => ['fr' => [...faqReady('Facture en double'), 'body' => 'Le paiement apparaît deux fois sur le relevé.']]]);
        $guides = ($this->category)('guides', 'Guides pratiques');
        $b = ($this->faq)('Exporter les clients', ['category_id' => $guides->id, 'slug' => 'export-clients']);

        $search = fn (string $term, array $see, array $hide) => ($this->list)()->set('activeTab', 'all')->searchTable($term)->assertCanSeeTableRecords(($this->records)($see))->assertCanNotSeeTableRecords(($this->records)($hide));

        $search('double', [$a], [$b]);
        $search('relevé', [$a], [$b]);
        $search('remboursement', [$a], [$b]);
        $search('Guides pratiques', [$b], [$a]);
        $search('export-clients', [$b], [$a]);
        $search('English: Exporter', [$b], [$a]);
        $search('résumé assez long', [$a, $b], []);
    });

    it('filtre par état, catégorie, langue, traduction incomplète, auteur et dates', function (): void {
        $a = ($this->faq)('FAQ complète en deux langues');
        $guides = ($this->category)('guides', 'Guides');
        $b = ($this->faq)('FAQ seulement en français', ['category_id' => $guides->id, 'translations' => ['fr' => faqReady('FAQ seulement en français')]]);
        ($this->manager)()->publish($this->boss, $a);
        $filter = fn (string $name, mixed $value, array $see, array $hide) => ($this->list)()->set('activeTab', 'all')->filterTable($name, $value)->assertCanSeeTableRecords(($this->records)($see))->assertCanNotSeeTableRecords(($this->records)($hide));

        $filter('status', ['published'], [$a], [$b]);
        $filter('category', $guides->id, [$b], [$a]);
        $filter('language', 'en', [$a], [$b]);
        $filter('language', 'nl', [], [$a, $b]);
        $filter('incomplete', true, [$a, $b], []);
        $filter('author', $this->boss->id, [$a, $b], []);
        $filter('author', $this->editor->id, [], [$a, $b]);
        $filter('dates', ['from' => now()->toDateString(), 'until' => now()->toDateString()], [$a, $b], []);
        $filter('dates', ['from' => now()->addDay()->toDateString()], [], [$a, $b]);
    });

    it('trie par ordre, mise à jour et nombre d\'avis', function (): void {
        $first = ($this->faq)('Première FAQ', ['position' => 20]);
        $this->travel(2)->seconds();
        $second = ($this->faq)('Deuxième FAQ', ['position' => 10]);
        ($this->manager)()->publish($this->boss, $first);
        ($this->manager)()->publish($this->boss, $second);
        $this->asOwner(fn () => DB::table('help_article_feedback')->insert([
            ['id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'user_id' => $this->owner->id, 'locale' => 'fr', 'category' => 'novelties', 'slug' => $first->slug, 'helpful' => true, 'answered_on' => now()->toDateString(), 'created_at' => now()],
        ]));

        ($this->list)()->set('activeTab', 'all')->sortTable('position', 'asc')->assertCanSeeTableRecords(($this->records)([$second, $first]), inOrder: true);
        ($this->list)()->set('activeTab', 'all')->sortTable('updated_at', 'desc')->assertCanSeeTableRecords(($this->records)([$second, $first]), inOrder: true);
        ($this->list)()->set('activeTab', 'all')->sortTable('feedback_total', 'desc')->assertCanSeeTableRecords(($this->records)([$first, $second]), inOrder: true)
            ->assertTableColumnStateSet('feedback_total', '1 utile(s) · 0 pas utile(s)', $first->id);
    });

    it('publie, dépublie, duplique, archive, restaure et supprime depuis la ligne', function (): void {
        $article = ($this->faq)('Action depuis la ligne');
        $list = fn () => ($this->list)()->set('activeTab', 'all');

        $list()->callTableAction('publish', $article->id);
        expect(($this->fresh)($article)->status)->toBe('published');
        $list()->callTableAction('unpublish', $article->id);
        expect(($this->fresh)($article)->status)->toBe('draft');
        $list()->callTableAction('duplicate', $article->id);
        expect(PlatformFaqArticle::query()->where('slug', $article->slug.'-copy')->exists())->toBeTrue();
        $list()->callTableAction('archive', $article->id);
        expect(($this->fresh)($article)->status)->toBe('archived');
        $list()->callTableAction('restore', $article->id);
        expect(($this->fresh)($article)->status)->toBe('draft');
        $list()->callTableAction('delete', $article->id);
        expect(PlatformFaqArticle::query()->whereKey($article->id)->exists())->toBeFalse();
    });

    it('refuse de publier une FAQ incomplète et le dit', function (): void {
        $article = ($this->faq)('Presque prête', ['translations' => ['fr' => faqReady('Presque prête')]]);

        ($this->list)()->set('activeTab', 'all')->callTableAction('publish', $article->id)->assertNotified(__('faq.errors.title'));
        expect(($this->fresh)($article)->status)->toBe('draft');
    });

    it('applique les actions en lot : publier, dépublier, archiver, changer de catégorie, supprimer', function (): void {
        $a = ($this->faq)('Lot A');
        $b = ($this->faq)('Lot B');
        $incomplete = ($this->faq)('Lot incomplet', ['translations' => ['fr' => faqReady('Lot incomplet')]]);
        $all = ($this->records)([$a, $b, $incomplete]);
        $guides = ($this->category)('guides', 'Guides');

        ($this->list)()->set('activeTab', 'all')->callTableBulkAction('publish', $all);
        expect(($this->fresh)($a)->status)->toBe('published')->and(($this->fresh)($b)->status)->toBe('published')->and(($this->fresh)($incomplete)->status)->toBe('draft');

        ($this->list)()->set('activeTab', 'all')->callTableBulkAction('category', ($this->records)([$a, $b]), data: ['category_id' => $guides->id]);
        expect(($this->fresh)($a)->category_id)->toBe($guides->id);

        ($this->list)()->set('activeTab', 'all')->callTableBulkAction('unpublish', ($this->records)([$a, $b]));
        expect(($this->fresh)($a)->status)->toBe('draft');

        ($this->list)()->set('activeTab', 'all')->callTableBulkAction('archive', ($this->records)([$a]));
        expect(($this->fresh)($a)->status)->toBe('archived');

        ($this->manager)()->publish($this->boss, ($this->fresh)($b));
        ($this->list)()->set('activeTab', 'all')->callTableBulkAction('delete', ($this->records)([$a, $b, $incomplete]));
        expect(PlatformFaqArticle::query()->whereKey($a->id)->exists())->toBeFalse()->and(PlatformFaqArticle::query()->whereKey($incomplete->id)->exists())->toBeFalse()
            ->and(PlatformFaqArticle::query()->whereKey($b->id)->exists())->toBeTrue(); // publiée : ignorée
        expect(($this->audit)('faq.bulk'))->toHaveCount(5);
    });

    it('montre un état vide clair', function (): void {
        ($this->list)()->assertSee(__('faq.empty.heading'));
    });
});

describe('éditeur', function (): void {
    it('crée une FAQ en brouillon depuis le formulaire, avec ses traductions et ses étiquettes', function (): void {
        $related = ($this->faq)('Article à lier');
        $category = ($this->category)();

        Livewire::actingAs($this->boss, 'platform')->test(CreateFaqArticle::class)
            ->fillForm([
                'category_id' => $category->id, 'position' => 4, 'publication' => 'available', 'tags' => ['équipe'], 'related' => [$related->id], 'is_popular' => true,
                'translations' => [
                    'fr' => ['question' => 'Comment inviter un collègue ?', 'summary' => 'Inviter par e-mail.', 'body' => "## Étapes\n\nOuvrez Réglages.", 'is_ready' => true],
                    'en' => ['question' => 'How do I invite a colleague?', 'summary' => 'Invite by email.', 'body' => "## Steps\n\nOpen Settings.", 'is_ready' => false],
                    'nl' => ['question' => '', 'summary' => '', 'body' => '', 'is_ready' => false],
                ],
            ])->call('create')->assertHasNoFormErrors()->assertNotified();

        $article = PlatformFaqArticle::query()->where('slug', 'comment-inviter-un-collegue')->firstOrFail();
        expect($article->status)->toBe('draft')->and($article->is_popular)->toBeTrue()->and($article->tags)->toBe(['équipe'])->and($article->related()->pluck('faq_articles.id')->all())->toBe([$related->id])
            ->and($article->translations()->count())->toBe(2)->and($article->translations()->where('is_ready', true)->count())->toBe(1);
    });

    it('exige la question, le résumé et le texte d\'une langue marquée prête', function (): void {
        Livewire::actingAs($this->boss, 'platform')->test(CreateFaqArticle::class)
            ->fillForm(['category_id' => ($this->category)()->id, 'translations' => ['fr' => ['question' => '', 'summary' => '', 'body' => '', 'is_ready' => true]]])
            ->call('create')->assertHasFormErrors(['translations.fr.question' => 'required', 'translations.fr.summary' => 'required', 'translations.fr.body' => 'required']);
    });

    it('refuse proprement une adresse déjà prise sans perdre la saisie', function (): void {
        ($this->faq)('Déjà là', ['slug' => 'deja-la']);

        Livewire::actingAs($this->boss, 'platform')->test(CreateFaqArticle::class)
            ->fillForm(['category_id' => ($this->category)()->id, 'slug' => 'deja-la', 'translations' => ['fr' => ['question' => 'Autre question', 'summary' => 'Résumé.', 'body' => 'Texte.', 'is_ready' => false]]])
            ->call('create')->assertNotified(__('faq.errors.title'));

        expect(PlatformFaqArticle::query()->count())->toBe(1);
    });

    it('charge la fiche, enregistre les modifications, consigne l\'audit et garde l\'historique', function (): void {
        $article = ($this->faq)('Question initiale');

        Livewire::actingAs($this->boss, 'platform')->test(EditFaqArticle::class, ['record' => $article->id])
            ->assertFormSet(['translations.fr.question' => 'Question initiale', 'translations.en.is_ready' => true, 'translations.nl.question' => ''])
            ->fillForm(['position' => 3, 'translations' => ['fr' => ['question' => 'Question modifiée', 'summary' => 'Un résumé assez long.', 'body' => 'Nouveau texte.', 'is_ready' => true]]])
            ->call('save')->assertHasNoFormErrors()->assertNotified();

        $after = ($this->fresh)($article);
        expect($after->position)->toBe(3)->and($after->translations()->where('locale', 'fr')->value('question'))->toBe('Question modifiée');
        expect(($this->audit)('faq.updated'))->toHaveCount(1)->and(($this->audit)('faq.translation_updated'))->toHaveCount(1);
        expect(FaqVersion::query()->where('article_id', $article->id)->where('locale', 'fr')->count())->toBe(2);
    });

    it('publie et dépublie depuis l\'éditeur, et explique ce qui manque', function (): void {
        $article = ($this->faq)('À publier', ['translations' => ['fr' => faqReady('À publier')]]);
        $page = fn () => Livewire::actingAs($this->boss, 'platform')->test(EditFaqArticle::class, ['record' => $article->id]);

        $page()->callAction('publish')->assertNotified(__('faq.errors.title'));
        expect(($this->fresh)($article)->status)->toBe('draft');

        ($this->manager)()->update($this->boss, ($this->fresh)($article), ['translations' => ['en' => faqReady('To publish', 'Body.')]]);
        $page()->callAction('publish');
        expect(($this->fresh)($article)->status)->toBe('published');
        $page()->assertActionHidden('remove')->callAction('unpublish');
        expect(($this->fresh)($article)->status)->toBe('draft');
    });

    it('ne laisse pas dépublier par erreur une FAQ publiée en la supprimant', function (): void {
        $article = ($this->faq)('Publiée');
        ($this->manager)()->publish($this->boss, $article);

        Livewire::actingAs($this->boss, 'platform')->test(EditFaqArticle::class, ['record' => $article->id])->assertActionHidden('remove')->assertActionHidden('restore')->assertActionHidden('publish');
        expect(FaqArticleResource::canDelete(($this->fresh)($article)))->toBeFalse();
    });

    it('rétablit une version depuis l\'historique', function (): void {
        $article = ($this->faq)('Version un');
        ($this->manager)()->update($this->boss, $article, ['translations' => ['fr' => faqReady('Version deux')]]);
        $first = FaqVersion::query()->where('article_id', $article->id)->where('locale', 'fr')->orderBy('created_at')->firstOrFail();

        Livewire::actingAs($this->boss, 'platform')->test(EditFaqArticle::class, ['record' => $article->id])->callAction('history', data: ['version' => $first->id]);

        expect(($this->fresh)($article)->translations()->where('locale', 'fr')->value('question'))->toBe('Version un');
    });

    it('duplique, archive et supprime depuis l\'éditeur', function (): void {
        $article = ($this->faq)('À copier');
        $page = fn () => Livewire::actingAs($this->boss, 'platform')->test(EditFaqArticle::class, ['record' => $article->id]);

        $page()->callAction('duplicate');
        expect(PlatformFaqArticle::query()->where('slug', $article->slug.'-copy')->exists())->toBeTrue();
        $page()->callAction('archive');
        expect(($this->fresh)($article)->status)->toBe('archived');
        $page()->callAction('restore');
        $page()->callAction('remove');
        expect(PlatformFaqArticle::query()->whereKey($article->id)->exists())->toBeFalse();
    });
});

describe('aperçu', function (): void {
    it('montre l\'article comme le client le verra, langue par langue, et dit pourquoi il est caché', function (): void {
        $related = ($this->faq)('Article lié');
        $article = ($this->faq)('Comment inviter ?', ['related' => [$related->id]]);
        $page = fn () => Livewire::actingAs($this->boss, 'platform')->test(PreviewFaqArticle::class, ['record' => $article->id]);

        $page()->assertSee('Comment inviter ?')->assertSee('<strong>Réglages</strong>', false)->assertSee(__('faq.preview.hidden'))
            ->assertSee(__('faq.preview.reason.hidden_status', ['language' => __('faq.locales.fr')]))->assertSee('Article lié')
            ->call('selectLocale', 'en')->assertSee('English: Comment inviter ?')
            ->call('selectLocale', 'nl')->assertSee(__('faq.preview.no_translation', ['language' => __('faq.locales.nl')]));

        ($this->manager)()->publish($this->boss, ($this->fresh)($article));
        $page()->assertSee(__('faq.preview.visible'))->assertSee(__('faq.preview.reason.visible', ['language' => __('faq.locales.fr')]))->assertActionHidden('publish')->assertActionVisible('unpublish');
    });

    it('retire le HTML brut et les liens dangereux, comme le centre d\'aide', function (): void {
        $article = ($this->faq)('Sécurité', ['translations' => ['fr' => faqReady('Sécurité', "<script>alert(1)</script>\n\n[piège](javascript:alert(1)) et [ok](https://rendivoo.example)")]]);

        $html = Livewire::actingAs($this->boss, 'platform')->test(PreviewFaqArticle::class, ['record' => $article->id])->html();

        expect($html)->not->toContain('<script>alert(1)')->not->toContain('href="javascript:')->toContain('https://rendivoo.example');
    });
});

describe('catégories', function (): void {
    it('liste les catégories avec leur nombre d\'articles et d\'articles publiés', function (): void {
        $category = ($this->category)();
        $a = ($this->faq)('Un article');
        ($this->faq)('Un autre article');
        ($this->manager)()->publish($this->boss, $a);

        Livewire::actingAs($this->boss, 'platform')->test(ManageFaqCategories::class)->assertSuccessful()
            ->assertTableColumnStateSet('articles_count', 2, $category->id)->assertTableColumnStateSet('names', 'Nouveautés', $category->id)->assertSee('1 publié(s)');
    });

    it('crée, modifie, désactive et réactive une catégorie', function (): void {
        $page = fn () => Livewire::actingAs($this->boss, 'platform')->test(ManageFaqCategories::class);

        $page()->callAction('create', data: ['slug' => 'securite', 'icon' => 'shield', 'position' => 3, 'is_active' => true, 'names' => ['fr' => 'Sécurité', 'en' => 'Security', 'nl' => ''], 'descriptions' => ['fr' => 'Mots de passe et 2FA']])->assertHasNoActionErrors();
        $category = PlatformFaqCategory::query()->where('slug', 'securite')->firstOrFail();
        expect($category->names)->toEqual(['fr' => 'Sécurité', 'en' => 'Security'])->and($category->icon)->toBe('shield');

        $page()->callTableAction('edit', $category->id, data: ['icon' => 'users', 'position' => 1, 'is_active' => true, 'names' => ['fr' => 'Sécurité du compte', 'en' => 'Account security', 'nl' => 'Accountbeveiliging'], 'descriptions' => []]);
        expect(PlatformFaqCategory::query()->findOrFail($category->id))->name('nl')->toBe('Accountbeveiliging');

        $page()->callTableAction('toggle', $category->id);
        expect(PlatformFaqCategory::query()->findOrFail($category->id)->is_active)->toBeFalse();
        $page()->callTableAction('toggle', $category->id);
        expect(PlatformFaqCategory::query()->findOrFail($category->id)->is_active)->toBeTrue();
    });

    it('refuse une catégorie sans nom français ou avec une adresse réservée', function (): void {
        Livewire::actingAs($this->boss, 'platform')->test(ManageFaqCategories::class)
            ->callAction('create', data: ['slug' => 'sans-nom', 'names' => ['fr' => '']])->assertHasActionErrors(['names.fr' => 'required']);
        Livewire::actingAs($this->boss, 'platform')->test(ManageFaqCategories::class)
            ->callAction('create', data: ['slug' => 'contact', 'names' => ['fr' => 'Contact']])->assertNotified(__('faq.errors.title'));
        expect(PlatformFaqCategory::query()->where('slug', 'contact')->exists())->toBeFalse();
    });

    it('ne supprime pas une catégorie qui contient des articles sans les réaffecter', function (): void {
        $category = ($this->category)();
        $other = ($this->category)('guides', 'Guides');
        $article = ($this->faq)('Dans la catégorie');
        $page = fn () => Livewire::actingAs($this->boss, 'platform')->test(ManageFaqCategories::class);

        $page()->callTableAction('remove', $category->id, data: [])->assertHasTableActionErrors(['move_to' => 'required']);
        expect(PlatformFaqCategory::query()->whereKey($category->id)->exists())->toBeTrue();

        $page()->callTableAction('remove', $category->id, data: ['move_to' => $other->id]);
        expect(PlatformFaqCategory::query()->whereKey($category->id)->exists())->toBeFalse()->and(($this->fresh)($article)->category_id)->toBe($other->id);
    });

    it('supprime sans question une catégorie vide', function (): void {
        $empty = ($this->category)('vide', 'Vide');

        Livewire::actingAs($this->boss, 'platform')->test(ManageFaqCategories::class)->callTableAction('remove', $empty->id);

        expect(PlatformFaqCategory::query()->whereKey($empty->id)->exists())->toBeFalse();
    });
});
