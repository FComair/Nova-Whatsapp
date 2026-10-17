<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\OrganizationLifecycle;
use App\Modules\Privacy\Services\DeletionPipeline;
use App\Modules\Tenancy\Models\Organization;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin > Clients : la liste et chaque fiche s'ouvrent quel que soit l'état du compte (essai, suspendu, suppression programmée,
 * supprimé), y compris juste après une suppression menée à son terme.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $this->admin = $this->admin->refresh();
});

it('ouvre la liste et la fiche de chaque client, quel que soit son état', function (string $status): void {
    $this->asOwner(fn () => $this->org = Organization::factory()->create(['name' => 'Client '.$status, 'status' => $status]));

    $this->actingAs($this->admin, 'platform')->get('/admin/organizations')->assertOk();
    $this->actingAs($this->admin, 'platform')->get('/admin/organizations/'.$this->org->id)->assertOk();
})->with(['trial', 'active', 'past_due', 'suspended', 'paused', 'pending_deletion', 'deleted']);

it('ouvre la liste après une suppression menée à son terme, et la fiche effacée répond par un 404 propre', function (): void {
    ['org' => $org] = $this->activatedAccount(['name' => 'Salon Test', 'status' => 'trial']);

    app(OrganizationLifecycle::class)->deleteNow(PlatformOrganization::query()->findOrFail($org->id), $this->admin->id, null);
    app(DeletionPipeline::class)->run();

    $this->actingAs($this->admin, 'platform')->get('/admin/organizations')->assertOk();
    $this->actingAs($this->admin, 'platform')->get('/admin/organizations/'.$org->id)->assertNotFound();
});

it('trie, filtre et recherche la liste des clients sans erreur', function (): void {
    $this->asOwner(function (): void {
        foreach (['trial', 'active', 'suspended', 'pending_deletion', 'deleted'] as $status) {
            Organization::factory()->create(['name' => 'Client '.$status, 'status' => $status]);
        }
    });

    $this->actingAs($this->admin, 'platform');
    $component = Livewire::test(ListOrganizations::class)->assertSuccessful();

    foreach ($component->instance()->getTable()->getColumns() as $column) {
        if ($column->isSortable()) {
            foreach (['asc', 'desc'] as $direction) {
                $component->call('sortTable', $column->getName(), $direction)->assertSuccessful();
            }
        }
    }

    $component->call('resetTableFiltersForm')->assertSuccessful();
    $component->set('tableSearch', 'Client')->assertSuccessful();
});
