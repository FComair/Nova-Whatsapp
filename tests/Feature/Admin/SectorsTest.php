<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\SectorResource;
use App\Modules\Admin\Filament\Resources\SectorResource\Pages\ListSectors;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Services\SectorManager;
use App\Modules\Tenancy\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin > Secteurs d'activité : saisie du nom, unicité, modification, activation, désactivation, suppression, audit.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->page = fn () => Livewire::actingAs($this->admin, 'platform')->test(ListSectors::class);
    $this->sector = fn (string $code): PlatformSector => PlatformSector::query()->where('code', $code)->firstOrFail();
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
    $this->merchants = fn (string $sector, int $count) => $this->asOwner(fn () => Organization::factory()->count($count)->create(['sector' => $sector]));
    $this->create = fn (array $data = []) => ($this->page)()->callAction('create', data: ['name' => 'Food & Beverage', ...$data]);
});

describe('liste', function (): void {
    it('montre les secteurs de départ avec le nombre d\'établissements de chacun', function (): void {
        ($this->merchants)('driving_school', 3);

        $beauty = ($this->sector)('beauty');
        $driving = ($this->sector)('driving_school');

        ($this->page)()->assertSuccessful()
            ->assertCanSeeTableRecords(PlatformSector::query()->get())
            ->assertTableColumnStateSet('merchants_count', 3, $driving)
            ->assertTableColumnStateSet('merchants_count', 0, $beauty);
    });

    it('cherche par nom et filtre par état', function (): void {
        ($this->sector)('driving_school')->forceFill(['is_active' => false])->save();

        ($this->page)()->searchTable('Dentist')->assertCanSeeTableRecords([($this->sector)('dental')])->assertCanNotSeeTableRecords([($this->sector)('beauty')]);
        ($this->page)()->filterTable('is_active', false)->assertCanSeeTableRecords([($this->sector)('driving_school')])->assertCanNotSeeTableRecords([($this->sector)('beauty')]);
        ($this->page)()->filterTable('is_active', true)->assertCanNotSeeTableRecords([($this->sector)('driving_school')]);
    });

    it('est réservée au Super Admin', function (): void {
        $this->get('/admin/sectors')->assertRedirect();

        ['user' => $owner, 'org' => $org] = $this->activatedAccount();
        $this->signedIn($owner, $org)->get('/admin/sectors')->assertRedirect();
    });
});

describe('ajout', function (): void {
    it('crée le secteur avec le nom saisi, un code technique, actif par défaut, et trace l\'action', function (): void {
        ($this->create)(['name' => '  Food   &  Beverage '])->assertHasNoActionErrors();

        $sector = ($this->sector)('food_beverage');
        expect($sector)->name->toBe('Food & Beverage')->is_active->toBeTrue()->is_default->toBeFalse()->name_fr->toBeNull();

        $log = ($this->audit)('sector.created')->sole();
        expect($log)->actor_id->toBe($this->admin->id)->entity_id->toBe($sector->id)
            ->and(json_decode($log->diff, true))->toMatchArray(['name' => 'Food & Beverage', 'code' => 'food_beverage', 'is_active' => true]);
    });

    it('peut créer le secteur inactif, avec traductions et notes', function (): void {
        ($this->create)(['is_active' => false, 'name_fr' => 'Restauration', 'name_nl' => '', 'notes' => 'Pour les restaurants'])->assertHasNoActionErrors();

        expect(($this->sector)('food_beverage'))->is_active->toBeFalse()->name_fr->toBe('Restauration')->name_nl->toBeNull()->notes->toBe('Pour les restaurants');
    });

    it('refuse un nom déjà pris, sans tenir compte des majuscules, des espaces ni des accents', function (string $name): void {
        ($this->create)(['name' => 'Café'])->assertHasNoActionErrors();

        ($this->create)(['name' => $name])->assertHasActionErrors(['name']);

        expect(PlatformSector::query()->where('name_key', 'cafe')->count())->toBe(1);
    })->with(['CAFÉ', 'cafe', '  CAFE  ', 'Cafè']);

    it('refuse aussi le nom d\'un secteur de départ', function (): void {
        ($this->create)(['name' => 'hairdresser & BARBER'])->assertHasActionErrors(['name']);
    });

    it('exige un nom d\'au moins 2 et d\'au plus 60 caractères', function (string $name): void {
        ($this->create)(['name' => $name])->assertHasActionErrors(['name']);
    })->with(['vide' => [''], 'trop court' => ['a'], 'trop long' => [str_repeat('x', 61)]]);

    it('donne un code différent à deux noms qui s\'écriraient pareil', function (): void {
        ($this->create)(['name' => 'Food & Beverage'])->assertHasNoActionErrors();
        ($this->create)(['name' => 'Food - Beverage'])->assertHasNoActionErrors();

        expect(PlatformSector::query()->whereIn('code', ['food_beverage', 'food_beverage_2'])->count())->toBe(2);
    });
});

describe('modification', function (): void {
    it('renomme le secteur sans changer son code ni ses établissements, et trace l\'ancien et le nouveau nom', function (): void {
        ($this->merchants)('coaching', 2);
        $coaching = ($this->sector)('coaching');

        ($this->page)()->callTableAction('edit', $coaching, data: ['name' => 'Coaching & training', 'is_active' => true])->assertHasNoTableActionErrors();

        expect(($this->sector)('coaching'))->name->toBe('Coaching & training')
            ->and(DB::connection('pgsql_owner')->table('organizations')->where('sector', 'coaching')->count())->toBe(2);

        $diff = json_decode(($this->audit)('sector.updated')->sole()->diff, true);
        expect($diff['name'])->toBe(['Coaching and consulting', 'Coaching & training']);
    });

    it('accepte de garder son propre nom, même écrit autrement', function (): void {
        ($this->page)()->callTableAction('edit', ($this->sector)('dental'), data: ['name' => 'DENTIST', 'is_active' => true])->assertHasNoTableActionErrors();
    });

    it('refuse le nom d\'un autre secteur', function (): void {
        ($this->page)()->callTableAction('edit', ($this->sector)('coaching'), data: ['name' => 'beauty and AESTHETICS', 'is_active' => true])->assertHasTableActionErrors(['name']);

        expect(($this->sector)('coaching')->name)->toBe('Coaching and consulting');
    });

    it('n\'écrit rien dans l\'audit quand rien ne change', function (): void {
        $dental = ($this->sector)('dental');

        ($this->page)()->callTableAction('edit', $dental, data: ['name' => 'Dentist', 'is_active' => true]);

        expect(($this->audit)('sector.updated'))->toHaveCount(0);
    });
});

describe('activation et désactivation', function (): void {
    it('désactive sans toucher aux établissements, et trace le nombre d\'établissements concernés', function (): void {
        ($this->merchants)('driving_school', 3);
        $driving = ($this->sector)('driving_school');

        ($this->page)()->callTableAction('deactivate', $driving);

        expect(($this->sector)('driving_school')->is_active)->toBeFalse()
            ->and(DB::connection('pgsql_owner')->table('organizations')->where('sector', 'driving_school')->count())->toBe(3);

        $log = ($this->audit)('sector.deactivated')->sole();
        expect(json_decode($log->diff, true))->toMatchArray(['merchants' => 3]);
    });

    it('réactive un secteur désactivé', function (): void {
        ($this->sector)('garage')->forceFill(['is_active' => false])->save();

        ($this->page)()->callTableAction('activate', ($this->sector)('garage'));

        expect(($this->sector)('garage')->is_active)->toBeTrue()
            ->and(($this->audit)('sector.activated'))->toHaveCount(1);
    });

    it('propose seulement l\'action qui a un sens selon l\'état', function (): void {
        ($this->sector)('garage')->forceFill(['is_active' => false])->save();

        ($this->page)()
            ->assertTableActionHidden('deactivate', ($this->sector)('garage'))->assertTableActionVisible('activate', ($this->sector)('garage'))
            ->assertTableActionVisible('deactivate', ($this->sector)('dental'))->assertTableActionHidden('activate', ($this->sector)('dental'));
    });
});

describe('suppression', function (): void {
    it('supprime le secteur et déplace ses établissements vers « Autre », avec trace', function (): void {
        ($this->merchants)('driving_school', 3);
        $driving = ($this->sector)('driving_school');

        ($this->page)()->callTableAction('delete', $driving);

        expect(PlatformSector::query()->where('code', 'driving_school')->exists())->toBeFalse()
            ->and(DB::connection('pgsql_owner')->table('organizations')->where('sector', 'other')->count())->toBe(3)
            ->and(DB::connection('pgsql_owner')->table('organizations')->where('sector', 'driving_school')->count())->toBe(0);

        $diff = json_decode(($this->audit)('sector.deleted')->sole()->diff, true);
        expect($diff)->toMatchArray(['code' => 'driving_school', 'merchants_moved' => 3, 'moved_to' => 'other']);
    });

    it('supprime un secteur que personne n\'utilise', function (): void {
        ($this->page)()->callTableAction('delete', ($this->sector)('real_estate'));

        expect(PlatformSector::query()->where('code', 'real_estate')->exists())->toBeFalse();
    });

    it('annonce l\'effet de la suppression et de la désactivation : nombre d\'établissements et secteur de repli', function (): void {
        $garage = ($this->sector)('garage');
        $fallback = ($this->sector)('other')->label(app()->getLocale());
        $in = fn (int $count): array => ['count' => $count, 'default' => $fallback];

        expect(SectorResource::impact($garage, 'delete'))->toBe(trans_choice('admin.sectors.confirm.delete_unused', 0, $in(0)))
            ->and(SectorResource::impact($garage, 'deactivate'))->toBe(trans_choice('admin.sectors.confirm.deactivate_unused', 0, $in(0)));

        ($this->merchants)('garage', 1);
        expect(SectorResource::impact($garage, 'delete'))->toBe(trans_choice('admin.sectors.confirm.delete_used', 1, $in(1)))
            ->and(SectorResource::impact($garage, 'delete'))->toContain($fallback);

        ($this->merchants)('garage', 1);
        expect(SectorResource::impact($garage, 'delete'))->toBe(trans_choice('admin.sectors.confirm.delete_used', 2, $in(2)))
            ->and(SectorResource::impact($garage, 'delete'))->toContain('2')
            ->and(SectorResource::impact($garage, 'deactivate'))->toBe(trans_choice('admin.sectors.confirm.deactivate_used', 2, $in(2)))
            ->and(SectorResource::impact($garage, 'deactivate'))->toContain('2');
    });
});

describe('secteur « Autre »', function (): void {
    it('se désactive et se réactive depuis la liste comme les autres secteurs, avec trace', function (): void {
        $other = ($this->sector)('other');

        ($this->page)()->assertTableActionVisible('deactivate', $other)->callTableAction('deactivate', $other);
        expect(($this->sector)('other')->is_active)->toBeFalse()->and(($this->audit)('sector.deactivated'))->toHaveCount(1);

        ($this->page)()->assertTableActionVisible('activate', ($this->sector)('other'))->assertTableActionHidden('deactivate', ($this->sector)('other'))->callTableAction('activate', ($this->sector)('other'));
        expect(($this->sector)('other')->is_active)->toBeTrue()->and(($this->audit)('sector.activated'))->toHaveCount(1);
    });

    it('se désactive aussi depuis le formulaire de modification, et l\'état persiste', function (): void {
        ($this->page)()->callTableAction('edit', ($this->sector)('other'), data: ['name' => 'Other', 'is_active' => false]);

        expect(($this->sector)('other')->is_active)->toBeFalse()
            ->and(DB::connection('pgsql_owner')->table('sectors')->where('code', 'other')->value('is_active'))->toBeFalse();

        ($this->page)()->callTableAction('edit', ($this->sector)('other'), data: ['name' => 'Other', 'is_active' => true]);
        expect(($this->sector)('other')->is_active)->toBeTrue();
    });

    it('n\'est plus proposé à l\'inscription une fois désactivé, mais reste choisi par les établissements qui l\'ont', function (): void {
        app(SectorManager::class)->setActive(($this->sector)('other'), false, $this->admin->id);
        $catalog = app(\App\Modules\Tenancy\Services\SectorCatalog::class);

        expect($catalog->codes())->not->toContain('other')->and(collect($catalog->options('fr'))->pluck('value')->all())->not->toContain('other')
            ->and($catalog->codes('other'))->toContain('other')
            ->and(collect($catalog->options('fr', 'other'))->pluck('value')->all())->toContain('other');
    });

    it('ne touche pas aux établissements qui utilisent « Autre »', function (): void {
        ($this->merchants)('other', 3);

        app(SectorManager::class)->setActive(($this->sector)('other'), false, $this->admin->id);

        expect(DB::connection('pgsql_owner')->table('organizations')->where('sector', 'other')->count())->toBe(3)
            ->and(($this->audit)('sector.deactivated')->first()->diff)->toContain('"merchants":3');
    });

    it('garde au moins un secteur actif, quel qu\'il soit', function (): void {
        DB::connection('pgsql_owner')->table('sectors')->where('code', '<>', 'other')->update(['is_active' => false]);

        expect(fn () => app(SectorManager::class)->setActive(($this->sector)('other'), false, $this->admin->id))->toThrow(DomainException::class, __('admin.sectors.errors.last_active'));

        ($this->page)()->callTableAction('deactivate', ($this->sector)('other'));
        expect(($this->sector)('other')->is_active)->toBeTrue();
    });

    it('reste impossible à supprimer : il reçoit les établissements des secteurs supprimés', function (): void {
        $other = ($this->sector)('other');

        ($this->page)()->assertTableActionHidden('delete', $other);

        expect(fn () => app(SectorManager::class)->delete($other, $this->admin->id))->toThrow(DomainException::class);
    });

    it('reçoit encore les établissements d\'un secteur supprimé, même désactivé', function (): void {
        ($this->merchants)('garage', 2);
        app(SectorManager::class)->setActive(($this->sector)('other'), false, $this->admin->id);

        app(SectorManager::class)->delete(($this->sector)('garage'), $this->admin->id);

        expect(DB::connection('pgsql_owner')->table('organizations')->where('sector', 'other')->count())->toBe(2);
    });
});
