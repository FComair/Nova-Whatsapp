<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Espace du praticien : il renseigne LUI-MÊME ses services et ses disponibilités (flowchart 1C).
 */
function profileWeek(array $days = [1, 2, 3], array $ranges = [['start' => '09:00', 'end' => '17:00']]): array
{
    return ['hours' => array_map(fn (int $weekday): array => ['weekday' => $weekday, 'ranges' => in_array($weekday, $days, true) ? $ranges : []], range(1, 7))];
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['signup_plan' => 'business']);
    $this->main = $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->firstOrFail());

    $this->serviceIds = $this->asOwner(function (): array {
        foreach (['Coupe', 'Couleur', 'Brushing'] as $position => $name) {
            Service::query()->create(['org_id' => $this->org->id, 'name' => $name, 'duration_minutes' => 30, 'price_cents' => 2500, 'position' => $position]);
        }

        return Service::query()->orderBy('position')->pluck('id')->all();
    });

    // Praticien invité et déjà rattaché à l'organisation, sans services ni planning.
    [$this->user, $this->practitioner] = $this->asOwner(function (): array {
        $user = User::factory()->create(['email' => 'lea@example.com', 'phone_e164' => null, 'phone_verified_at' => null]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'practitioner']);
        $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'name' => 'Léa Bernard', 'color' => '#12A594']);

        return [$user, $practitioner];
    });

    $this->as = fn () => $this->signedIn($this->user, $this->org);
});

describe('page', function (): void {
    it('affiche la fiche du praticien connecté, ses services et son planning', function (): void {
        ($this->as)()->get('/mon-profil')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Practitioner')
            ->where('practitioner.id', $this->practitioner->id)
            ->where('practitioner.name', 'Léa Bernard')
            ->where('practitioner.bookable', false)
            ->where('practitioner.service_ids', [])
            ->has('services', 3)
            ->has('locations', 1)
            ->where("schedules.{$this->main->id}.1", []));
    });

    it('est accessible au propriétaire lié comme praticien', function (): void {
        $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'user_id' => $this->owner->id, 'name' => 'Aurore', 'color' => '#2A5BD7']));

        $this->signedIn($this->owner, $this->org)->get('/mon-profil')->assertOk()->assertInertia(fn (Assert $page) => $page->where('practitioner.name', 'Aurore'));
    });

    it('répond 404 quand le compte n\'est lié à aucun praticien', function (): void {
        $this->signedIn($this->owner, $this->org)->get('/mon-profil')->assertNotFound();
    });

    it('est refusé aux réceptionnistes et responsables de site', function (string $role): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: "{$role}@example.com", role: $role);

        $this->signedIn($user, $org)->get('/mon-profil')->assertForbidden();
    })->with(['site_manager', 'receptionist']);

    it('exige d\'être connecté', function (): void {
        $this->get('/mon-profil')->assertRedirect('/connexion');
    });

    it('signale un profil en brouillon', function (): void {
        $this->asOwner(fn () => $this->practitioner->forceFill(['status' => 'draft'])->save());

        ($this->as)()->get('/mon-profil')->assertInertia(fn (Assert $page) => $page->where('practitioner.status', 'draft'));
    });
});

describe('services', function (): void {
    it('enregistre les services du praticien', function (): void {
        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[0], $this->serviceIds[2]]])->assertSessionHasNoErrors();

        $rows = $this->asOwner(fn () => DB::table('practitioner_services')->where('practitioner_id', $this->practitioner->id)->get());
        expect($rows->pluck('service_id')->sort()->values()->all())->toBe(collect([$this->serviceIds[0], $this->serviceIds[2]])->sort()->values()->all())
            ->and($rows->pluck('org_id')->unique()->all())->toBe([$this->org->id]);
    });

    it('remplace la sélection précédente et autorise une liste vide', function (): void {
        ($this->as)()->put('/mon-profil/services', ['service_ids' => $this->serviceIds]);
        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[1]]]);
        expect($this->asOwner(fn () => DB::table('practitioner_services')->count()))->toBe(1);

        ($this->as)()->put('/mon-profil/services', ['service_ids' => []])->assertSessionHasNoErrors();
        expect($this->asOwner(fn () => DB::table('practitioner_services')->count()))->toBe(0);
    });

    it('trace le changement', function (): void {
        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[0]]]);

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.updated')->where('actor_id', $this->user->id)->count()))->toBe(1);
    });

    it('n\'écrit pas d\'audit quand rien ne change', function (): void {
        ($this->as)()->put('/mon-profil/services', ['service_ids' => []]);

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.updated')->count()))->toBe(0);
    });

    it('refuse un service d\'un autre client, supprimé, en double ou mal formé', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreign = $this->asOwner(fn () => Service::query()->create(['org_id' => $other->id, 'name' => 'Étranger', 'duration_minutes' => 30, 'price_cents' => 100]));
        $this->asOwner(fn () => Service::query()->find($this->serviceIds[2])->delete());

        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$foreign->id]])->assertSessionHasErrors('service_ids.0');
        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[2]]])->assertSessionHasErrors('service_ids.0');
        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[0], $this->serviceIds[0]]])->assertSessionHasErrors('service_ids.1');
        ($this->as)()->put('/mon-profil/services', ['service_ids' => ['abc']])->assertSessionHasErrors('service_ids.0');
        ($this->as)()->put('/mon-profil/services', [])->assertSessionHasErrors('service_ids');

        expect($this->asOwner(fn () => DB::table('practitioner_services')->count()))->toBe(0);
    });

    it('ne touche jamais aux services d\'un autre praticien', function (): void {
        $colleague = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Collègue', 'color' => '#7C3AED']);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->serviceIds[0]]);

            return $practitioner;
        });

        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[1]]]);

        expect($this->asOwner(fn () => DB::table('practitioner_services')->where('practitioner_id', $colleague->id)->pluck('service_id')->all()))->toBe([$this->serviceIds[0]]);
    });
});

describe('disponibilités', function (): void {
    beforeEach(function (): void {
        $this->put = fn (array $body, ?Location $site = null) => ($this->as)()->put('/mon-profil/horaires/'.($site ?? $this->main)->id, $body);
    });

    it('enregistre le planning du praticien', function (): void {
        ($this->put)(profileWeek())->assertSessionHasNoErrors();

        $rows = $this->asOwner(fn () => PractitionerSchedule::query()->where('practitioner_id', $this->practitioner->id)->orderBy('weekday')->get());
        expect($rows)->toHaveCount(3)->and($rows[0]->weekday)->toBe(1)->and(substr($rows[0]->starts_at, 0, 5))->toBe('09:00');
    });

    it('remplace le planning à chaque enregistrement', function (): void {
        ($this->put)(profileWeek());
        ($this->put)(profileWeek([6], [['start' => '10:00', 'end' => '12:00']]));

        expect($this->asOwner(fn () => PractitionerSchedule::query()->pluck('weekday')->all()))->toBe([6]);
    });

    it('refuse les plages invalides', function (array $ranges, string $field): void {
        ($this->put)(profileWeek([1], $ranges))->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(0);
    })->with([
        'fin avant le début' => [[['start' => '17:00', 'end' => '09:00']], 'hours.0.ranges.0.end'],
        'chevauchement' => [[['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '17:00']], 'hours.0.ranges.1.start'],
    ]);

    it('empêche d\'être sur deux sites à la même heure', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id]));
        ($this->put)(profileWeek([1], [['start' => '09:00', 'end' => '13:00']]));

        ($this->put)(profileWeek([1], [['start' => '12:00', 'end' => '17:00']]), $second)->assertSessionHasErrors('hours');
    });

    it('répond 404 pour le site d\'un autre client', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreign = $this->asOwner(fn () => Location::query()->where('org_id', $other->id)->firstOrFail());

        ($this->put)(profileWeek(), $foreign)->assertNotFound();
    });

    it('ne modifie que son propre planning', function (): void {
        $colleague = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Collègue', 'color' => '#7C3AED']);
            PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->main->id, 'weekday' => 4, 'starts_at' => '08:00', 'ends_at' => '12:00']);

            return $practitioner;
        });

        ($this->put)(profileWeek());

        expect($this->asOwner(fn () => PractitionerSchedule::query()->where('practitioner_id', $colleague->id)->count()))->toBe(1);
    });
});

describe('rendu réservable et sécurité', function (): void {
    it('devient réservable une fois les services et le planning renseignés, ce qui retire le bandeau du tableau de bord', function (): void {
        ($this->as)()->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('profile.complete', false));

        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[0]]]);
        ($this->as)()->put("/mon-profil/horaires/{$this->main->id}", profileWeek());

        ($this->as)()->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('profile.complete', true));
        ($this->as)()->get('/mon-profil')->assertInertia(fn (Assert $page) => $page->where('practitioner.bookable', true));
    });

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->as)()->put('/mon-profil/services', ['service_ids' => [$this->serviceIds[0]]])->assertRedirect('/compte');

        expect($this->asOwner(fn () => DB::table('practitioner_services')->count()))->toBe(0);
    });

    it('ne donne accès à aucune fiche par identifiant : la fiche est retrouvée par le compte', function (): void {
        // Aucune route de l'espace praticien n'accepte d'identifiant de praticien.
        $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route): bool => str_starts_with($route->uri(), 'mon-profil'));

        expect($routes)->not->toBeEmpty();
        foreach ($routes as $route) {
            expect($route->uri())->not->toContain('{practitioner}');
        }
    });
});
