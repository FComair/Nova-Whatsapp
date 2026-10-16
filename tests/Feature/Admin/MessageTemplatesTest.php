<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\MessageTemplateResource\Pages\ListMessageTemplates;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformMessageTemplate;
use App\Modules\Admin\Services\MessageTemplateImporter;
use App\Modules\Admin\Services\MessageTemplateManager;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin › Messages WhatsApp : isolation par secteur, circuit d'approbation, historique, import (docs/39).
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@nova.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->manager = fn (): MessageTemplateManager => app(MessageTemplateManager::class);
    $this->make = fn (array $data = []): PlatformMessageTemplate => ($this->manager)()->create([
        'sector_code' => 'hairdressing',
        'type' => 'reminder',
        'name' => 'Rappel',
        'translations' => ['fr' => 'Bonjour {{customer_name}}, rappel de votre rendez-vous le {{date}} à {{time}}. À bientôt !', 'nl' => '', 'en' => ''],
        ...$data,
    ], $this->admin->id);
    $this->events = fn (string $id) => DB::connection('pgsql_platform')->table('message_template_events')->where('template_id', $id)->orderBy('created_at')->pluck('action')->all();
});

describe('import', function (): void {
    it('importe un jeu complet en brouillon pour chaque secteur, sans écraser l\'existant', function (): void {
        $result = app(MessageTemplateImporter::class)->import(null, $this->admin->id);

        $sectors = DB::connection('pgsql_platform')->table('sectors')->count();
        expect($result['created'])->toBe($sectors * 12)
            ->and(DB::connection('pgsql_platform')->table('message_templates')->where('status', '<>', 'draft')->count())->toBe(0)
            ->and(DB::connection('pgsql_platform')->table('message_template_translations')->count())->toBe($sectors * 12 * 3);

        // Un modèle modifié puis un second import : rien n'est écrasé.
        $template = PlatformMessageTemplate::query()->where('sector_code', 'dental')->where('type', 'reminder')->firstOrFail();
        ($this->manager)()->update($template, ['name' => 'Rappel dentiste (relu)'], $this->admin->id);

        $again = app(MessageTemplateImporter::class)->import(null, $this->admin->id);

        expect($again)->toBe(['created' => 0, 'skipped' => $sectors * 12])
            ->and($template->fresh()->name)->toBe('Rappel dentiste (relu)');
    });

    it('n\'écrit que dans le secteur demandé', function (): void {
        app(MessageTemplateImporter::class)->import('garage', $this->admin->id);

        expect(DB::connection('pgsql_platform')->table('message_templates')->distinct()->pluck('sector_code')->all())->toBe(['garage']);
    });

    it('donne à chaque modèle un nom Meta qui porte son secteur', function (): void {
        app(MessageTemplateImporter::class)->import(null, $this->admin->id);

        $names = DB::connection('pgsql_platform')->table('message_templates')->pluck('meta_name');
        expect($names->unique()->count())->toBe($names->count())
            ->and(PlatformMessageTemplate::query()->where('sector_code', 'dental')->pluck('meta_name')->every(fn (string $name): bool => str_starts_with($name, 'nova_dental_')))->toBeTrue();
    });
});

describe('circuit d\'approbation', function (): void {
    it('suit brouillon → en attente → approuvé, et garde l\'historique', function (): void {
        $template = ($this->make)();
        expect($template->status)->toBe('draft');

        ($this->manager)()->submit($template, $this->admin->id);
        expect($template->fresh()->status)->toBe('pending_approval');

        ($this->manager)()->approve($template->fresh(), $this->admin->id);

        $approved = $template->fresh();
        expect($approved->status)->toBe('approved')->and($approved->usable())->toBeTrue()->and($approved->decided_by)->toBe($this->admin->id)
            ->and(($this->events)($template->id))->toBe(['created', 'submitted', 'approved']);
    });

    it('refuse un modèle avec un motif obligatoire, puis le laisse corriger et resoumettre', function (): void {
        $template = ($this->make)();
        ($this->manager)()->submit($template, $this->admin->id);

        expect(fn () => ($this->manager)()->reject($template->fresh(), '  ', $this->admin->id))->toThrow(InvalidArgumentException::class);

        ($this->manager)()->reject($template->fresh(), 'Ton trop familier', $this->admin->id);
        expect($template->fresh()->status)->toBe('rejected')->and($template->fresh()->rejection_reason)->toBe('Ton trop familier')->and($template->fresh()->usable())->toBeFalse();

        ($this->manager)()->submit($template->fresh(), $this->admin->id);
        expect($template->fresh()->status)->toBe('pending_approval')->and($template->fresh()->rejection_reason)->toBeNull();
    });

    it('ne soumet pas un texte que Meta refuserait', function (): void {
        $template = ($this->make)(['translations' => ['fr' => 'Bonjour {{customer_name}}', 'nl' => '', 'en' => '']]);

        expect(fn () => ($this->manager)()->submit($template, $this->admin->id))->toThrow(DomainException::class);
        expect($template->fresh()->status)->toBe('draft');
    });

    it('ne peut pas approuver un brouillon ni soumettre un modèle déjà approuvé', function (): void {
        $template = ($this->make)();

        expect(fn () => ($this->manager)()->approve($template, $this->admin->id))->toThrow(DomainException::class);

        ($this->manager)()->submit($template, $this->admin->id);
        ($this->manager)()->approve($template->fresh(), $this->admin->id);

        expect(fn () => ($this->manager)()->submit($template->fresh(), $this->admin->id))->toThrow(DomainException::class);
    });

    it('remet en brouillon un modèle approuvé dont on change le texte', function (): void {
        $template = ($this->make)();
        ($this->manager)()->submit($template, $this->admin->id);
        ($this->manager)()->approve($template->fresh(), $this->admin->id);

        ($this->manager)()->update($template->fresh(), ['translations' => ['fr' => 'Bonjour {{customer_name}}, nouveau texte le {{date}} à {{time}}. Merci !']], $this->admin->id);

        expect($template->fresh()->status)->toBe('draft')->and($template->fresh()->usable())->toBeFalse();
    });

    it('garde un modèle approuvé quand seul le nom interne change', function (): void {
        $template = ($this->make)();
        ($this->manager)()->submit($template, $this->admin->id);
        ($this->manager)()->approve($template->fresh(), $this->admin->id);

        ($this->manager)()->update($template->fresh(), ['name' => 'Rappel 24 h'], $this->admin->id);

        expect($template->fresh()->status)->toBe('approved')->and($template->fresh()->name)->toBe('Rappel 24 h');
    });

    it('désactive sans supprimer, archive, restaure en brouillon, et ne supprime que brouillon, refusé ou archivé', function (): void {
        $template = ($this->make)();
        ($this->manager)()->submit($template, $this->admin->id);
        ($this->manager)()->approve($template->fresh(), $this->admin->id);

        ($this->manager)()->setEnabled($template->fresh(), false, $this->admin->id);
        expect($template->fresh()->is_enabled)->toBeFalse()->and($template->fresh()->status)->toBe('approved')->and($template->fresh()->usable())->toBeFalse();

        expect(fn () => ($this->manager)()->delete($template->fresh(), $this->admin->id))->toThrow(DomainException::class);

        ($this->manager)()->archive($template->fresh(), $this->admin->id);
        expect($template->fresh()->status)->toBe('archived');

        ($this->manager)()->restore($template->fresh(), $this->admin->id);
        expect($template->fresh()->status)->toBe('draft');

        ($this->manager)()->delete($template->fresh(), $this->admin->id);
        expect(PlatformMessageTemplate::query()->whereKey($template->id)->exists())->toBeFalse()
            ->and(($this->events)($template->id))->toContain('created', 'approved', 'disabled', 'archived', 'restored', 'deleted');
    });
});

describe('secteurs indépendants', function (): void {
    it('duplique vers un autre secteur en brouillon indépendant', function (): void {
        $template = ($this->make)();
        ($this->manager)()->submit($template, $this->admin->id);
        ($this->manager)()->approve($template->fresh(), $this->admin->id);

        $copy = ($this->manager)()->duplicate($template->fresh(), 'dental', $this->admin->id);

        expect($copy->sector_code)->toBe('dental')->and($copy->status)->toBe('draft')->and($copy->id)->not->toBe($template->id)
            ->and($copy->meta_name)->toStartWith('nova_dental_')
            ->and(($this->manager)()->bodies($copy))->toBe(($this->manager)()->bodies($template));

        // Modifier ou supprimer la copie n'affecte pas l'original.
        ($this->manager)()->delete($copy, $this->admin->id);
        expect($template->fresh()->status)->toBe('approved');
    });

    it('accepte le même type dans deux secteurs sans collision de nom Meta', function (): void {
        $a = ($this->make)(['sector_code' => 'hairdressing']);
        $b = ($this->make)(['sector_code' => 'beauty']);

        expect($a->meta_name)->not->toBe($b->meta_name)->and($a->slug)->toBe($b->slug);
    });

    it('refuse un secteur ou un type inconnus', function (): void {
        expect(fn () => ($this->make)(['sector_code' => 'inconnu']))->toThrow(InvalidArgumentException::class)
            ->and(fn () => ($this->make)(['type' => 'inconnu']))->toThrow(InvalidArgumentException::class);
    });

    it('supprime les modèles d\'un secteur supprimé, mais garde leur historique', function (): void {
        $template = ($this->make)(['sector_code' => 'driving_school']);

        DB::connection('pgsql_platform')->table('sectors')->where('code', 'driving_school')->delete();

        expect(PlatformMessageTemplate::query()->whereKey($template->id)->exists())->toBeFalse()
            ->and(($this->events)($template->id))->toBe(['created']);
    });
});

describe('journal en ajout seul', function (): void {
    it('refuse toute modification ou suppression de l\'historique', function (): void {
        $template = ($this->make)();

        expect(fn () => DB::connection('pgsql_platform')->table('message_template_events')->where('template_id', $template->id)->update(['action' => 'x']))->toThrow(QueryException::class);
        expect(fn () => DB::connection('pgsql_platform')->table('message_template_events')->where('template_id', $template->id)->delete())->toThrow(QueryException::class);
    });

    it('est fermé à l\'application cliente, et les modèles lui sont en lecture seule', function (): void {
        $template = ($this->make)();

        expect(fn () => DB::table('message_template_events')->count())->toThrow(QueryException::class);
        expect(fn () => DB::table('message_templates')->where('id', $template->id)->update(['status' => 'approved']))->toThrow(QueryException::class);
        expect(DB::table('message_templates')->where('id', $template->id)->count())->toBe(1);
    });
});

describe('liste', function (): void {
    it('filtre par secteur, statut, type et langue, et cherche dans le texte', function (): void {
        $hair = ($this->make)();
        $dental = ($this->make)(['sector_code' => 'dental', 'type' => 'confirmation', 'name' => 'Confirmation', 'translations' => ['fr' => 'Bonjour {{customer_name}}, votre détartrage est confirmé le {{date}}. Merci !', 'nl' => 'Beste {{customer_name}}, uw afspraak is bevestigd op {{date}}. Dank!', 'en' => '']]);
        ($this->manager)()->submit($dental, $this->admin->id);

        $page = fn () => Livewire::actingAs($this->admin, 'platform')->test(ListMessageTemplates::class);

        $page()->assertSuccessful()->assertCanSeeTableRecords([$hair, $dental]);
        $page()->filterTable('sector_code', 'dental')->assertCanSeeTableRecords([$dental])->assertCanNotSeeTableRecords([$hair]);
        $page()->filterTable('status', ['pending_approval'])->assertCanSeeTableRecords([$dental])->assertCanNotSeeTableRecords([$hair]);
        $page()->filterTable('type', ['reminder'])->assertCanSeeTableRecords([$hair])->assertCanNotSeeTableRecords([$dental]);
        $page()->filterTable('language', ['language' => 'nl'])->assertCanSeeTableRecords([$dental])->assertCanNotSeeTableRecords([$hair]);
        $page()->searchTable('détartrage')->assertCanSeeTableRecords([$dental])->assertCanNotSeeTableRecords([$hair]);
    });

    it('crée un modèle depuis l\'écran et le montre en brouillon', function (): void {
        Livewire::actingAs($this->admin, 'platform')->test(ListMessageTemplates::class)
            ->callAction('create', data: [
                'sector_code' => 'beauty', 'type' => 'welcome', 'name' => 'Bienvenue institut', 'is_enabled' => true,
                'translations' => ['fr' => 'Bonjour {{customer_name}}, bienvenue chez {{business_name}} ! À bientôt.', 'nl' => '', 'en' => ''],
            ])
            ->assertHasNoActionErrors();

        $created = PlatformMessageTemplate::query()->where('name', 'Bienvenue institut')->firstOrFail();
        expect($created->sector_code)->toBe('beauty')->and($created->status)->toBe('draft');
    });
});
