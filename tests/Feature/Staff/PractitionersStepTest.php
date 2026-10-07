<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\Service;
use App\Modules\Onboarding\Models\OnboardingProgress;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Staff\Services\PractitionerManager;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Étape 5 : praticiens ajoutés par le propriétaire, services proposés, planning, limite de l'offre (E8).
 */
function practitionerPayload(array $serviceIds, array $override = []): array
{
    return array_merge(['name' => 'Julie Martin', 'color' => '#12A594', 'bio' => 'Coloriste', 'service_ids' => $serviceIds], $override);
}

/** Semaine envoyée par le formulaire de planning (lundi au vendredi par défaut). */
function scheduleWeek(array $days = [1, 2, 3, 4, 5], array $ranges = [['start' => '09:00', 'end' => '17:00']]): array
{
    return ['hours' => array_map(fn (int $weekday): array => ['weekday' => $weekday, 'ranges' => in_array($weekday, $days, true) ? $ranges : []], range(1, 7))];
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['signup_plan' => 'business'], onboarded: false);
    $this->main = $this->asOwner(fn () => Location::query()->where('org_id', $this->org->id)->firstOrFail());

    $this->asOwner(function (): void {
        foreach (range(1, 5) as $weekday) {
            BusinessHour::query()->create(['org_id' => $this->org->id, 'location_id' => $this->main->id, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '18:00']);
        }

        foreach (['Coupe', 'Couleur', 'Brushing'] as $position => $name) {
            Service::query()->create(['org_id' => $this->org->id, 'name' => $name, 'duration_minutes' => 30, 'price_cents' => 2500, 'position' => $position]);
        }
    });

    $this->serviceIds = $this->asOwner(fn () => Service::query()->orderBy('position')->pluck('id')->all());
    $this->as = fn () => $this->signedIn($this->owner, $this->org);
    $this->add = fn (array $override = []) => ($this->as)()->post('/configuration/praticiens', practitionerPayload($this->serviceIds, $override));
    $this->first = fn () => $this->asOwner(fn () => Practitioner::query()->orderBy('position')->firstOrFail());
});

describe('page', function (): void {
    it('affiche les praticiens, les services, les sites et l\'utilisation de l\'offre', function (): void {
        ($this->as)()->get('/configuration/assistant/praticiens')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Onboarding/Practitioners')
            ->where('practitioners', [])
            ->has('services', 3)
            ->has('locations', 1)
            ->where('colors.0', '#2A5BD7')
            ->where('plan', ['name' => 'Business', 'limit' => 5, 'used' => 0])
            ->where('meLinked', false));
    });

    it('détaille chaque praticien avec son planning par site', function (): void {
        ($this->add)();

        ($this->as)()->get('/configuration/assistant/praticiens')->assertInertia(fn (Assert $page) => $page
            ->has('practitioners', 1)
            ->where('practitioners.0.name', 'Julie Martin')
            ->where('practitioners.0.status', 'active')
            ->where('practitioners.0.bookable', true)
            ->has('practitioners.0.service_ids', 3)
            ->where("practitioners.0.schedules.{$this->main->id}.1", [['start' => '09:00', 'end' => '18:00']])
            ->where("practitioners.0.schedules.{$this->main->id}.6", []));
    });
});

describe('création', function (): void {
    it('crée un praticien actif avec ses services et le planning du site principal', function (): void {
        ($this->add)()->assertSessionHasNoErrors();

        $practitioner = ($this->first)();
        expect($practitioner->status)->toBe('active')
            ->and($practitioner->color)->toBe('#12A594')
            ->and($practitioner->position)->toBe(1)
            ->and($practitioner->user_id)->toBeNull();

        expect($this->asOwner(fn () => DB::table('practitioner_services')->where('practitioner_id', $practitioner->id)->pluck('org_id')->unique()->all()))->toBe([$this->org->id])
            ->and($this->asOwner(fn () => DB::table('practitioner_services')->where('practitioner_id', $practitioner->id)->count()))->toBe(3)
            ->and($this->asOwner(fn () => PractitionerSchedule::query()->where('practitioner_id', $practitioner->id)->count()))->toBe(5);
    });

    it('ne copie pas les horaires quand on ne le demande pas', function (): void {
        ($this->add)(['copy_hours' => false]);

        expect($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(0);
    });

    it('met le nom de couleur en majuscules et supprime les espaces du nom', function (): void {
        ($this->add)(['name' => '  Paul  ']);

        expect(($this->first)()->name)->toBe('Paul');
    });

    it('permet au propriétaire de s\'ajouter comme praticien', function (): void {
        ($this->add)(['is_me' => true])->assertSessionHasNoErrors();

        expect(($this->first)()->user_id)->toBe($this->owner->id);
        ($this->as)()->get('/configuration/assistant/praticiens')->assertInertia(fn (Assert $page) => $page->where('meLinked', true)->where('practitioners.0.is_me', true));
    });

    it('refuse de s\'ajouter deux fois', function (): void {
        ($this->add)(['is_me' => true]);
        ($this->add)(['is_me' => true, 'name' => 'Encore moi'])->assertSessionHasErrors(['is_me' => 'Vous êtes déjà ajouté comme praticien.']);

        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(1);
    });

    it('trace la création', function (): void {
        ($this->add)();

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.created')->first());
        expect(json_decode($log->diff, true))->toEqual(['name' => 'Julie Martin', 'status' => 'active']);
    });

    it('refuse les données invalides', function (array $override, string $field): void {
        ($this->add)($override)->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(0);
    })->with([
        'nom vide' => [['name' => ''], 'name'],
        'nom trop court' => [['name' => 'J'], 'name'],
        'couleur hors palette' => [['color' => '#000000'], 'color'],
        'couleur invalide' => [['color' => 'rouge'], 'color'],
        'présentation trop longue' => [['bio' => str_repeat('a', 1001)], 'bio'],
        'service inconnu' => [['service_ids' => ['00000000-0000-4000-8000-000000000000']], 'service_ids.0'],
        'identifiant invalide' => [['service_ids' => ['abc']], 'service_ids.0'],
    ]);

    it('exige le champ des services (même vide)', function (): void {
        ($this->as)()->post('/configuration/praticiens', ['name' => 'Julie', 'color' => '#12A594'])->assertSessionHasErrors('service_ids');
    });

    it('accepte un praticien sans service (il ne sera pas réservable)', function (): void {
        ($this->as)()->post('/configuration/praticiens', practitionerPayload([]))->assertSessionHasNoErrors();

        expect(app(PractitionerManager::class)->isBookable(($this->first)()))->toBeFalse();
    });

    it('refuse un service supprimé ou celui d\'un autre client', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreign = $this->asOwner(fn () => Service::query()->create(['org_id' => $other->id, 'name' => 'Étranger', 'duration_minutes' => 30, 'price_cents' => 100]));
        $deleted = $this->asOwner(fn () => Service::query()->find($this->serviceIds[0]));
        $this->asOwner(fn () => $deleted->delete());

        ($this->add)(['service_ids' => [$foreign->id]])->assertSessionHasErrors('service_ids.0');
        ($this->add)(['service_ids' => [$deleted->id]])->assertSessionHasErrors('service_ids.0');
        ($this->add)(['service_ids' => [$this->serviceIds[1], $this->serviceIds[1]]])->assertSessionHasErrors('service_ids.1');

        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(0);
    });
});

describe('limite de l\'offre (E8)', function (): void {
    it('crée le praticien en brouillon au-delà de la limite, avec le message E8', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());

        ($this->add)(['name' => 'Premier'])->assertSessionHas('status');
        ($this->add)(['name' => 'Second'])->assertSessionHas('warning', 'Votre plan inclut 1 praticien(s). Passez au plan supérieur pour en ajouter.');

        $second = $this->asOwner(fn () => Practitioner::query()->where('name', 'Second')->firstOrFail());
        expect($second->status)->toBe('draft')
            ->and(app(PractitionerManager::class)->isBookable($second))->toBeFalse();
    });

    it('conserve la configuration du brouillon (services et planning)', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->add)(['name' => 'Premier']);
        ($this->add)(['name' => 'Second']);

        $second = $this->asOwner(fn () => Practitioner::query()->where('name', 'Second')->firstOrFail());
        expect($this->asOwner(fn () => DB::table('practitioner_services')->where('practitioner_id', $second->id)->count()))->toBe(3)
            ->and($this->asOwner(fn () => PractitionerSchedule::query()->where('practitioner_id', $second->id)->count()))->toBe(5);
    });

    it('ne compte pas les brouillons dans les places utilisées', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->add)(['name' => 'Premier']);
        ($this->add)(['name' => 'Second']);

        ($this->as)()->get('/configuration/assistant/praticiens')->assertInertia(fn (Assert $page) => $page->where('plan', ['name' => 'Basic', 'limit' => 1, 'used' => 1]));
    });

    it('applique la limite de 5 praticiens du plan Business', function (): void {
        foreach (range(1, 5) as $i) {
            ($this->add)(['name' => "Praticien {$i}"])->assertSessionHas('status');
        }

        ($this->add)(['name' => 'Sixième'])->assertSessionHas('warning');

        expect($this->asOwner(fn () => Practitioner::query()->where('status', 'active')->count()))->toBe(5)
            ->and($this->asOwner(fn () => Practitioner::query()->where('status', 'draft')->count()))->toBe(1);
    });

    it('refuse d\'activer un brouillon tant que la limite est atteinte', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->add)(['name' => 'Premier']);
        ($this->add)(['name' => 'Second']);
        $second = $this->asOwner(fn () => Practitioner::query()->where('name', 'Second')->firstOrFail());

        ($this->as)()->post("/configuration/praticiens/{$second->id}/activer")
            ->assertSessionHasErrors(['limit' => 'Votre plan inclut 1 praticien(s). Passez au plan supérieur pour en ajouter.']);

        expect($this->asOwner(fn () => Practitioner::query()->find($second->id)->status))->toBe('draft');
    });

    it('active le brouillon dès qu\'une place se libère ou que l\'offre change', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->add)(['name' => 'Premier']);
        ($this->add)(['name' => 'Second']);
        $second = $this->asOwner(fn () => Practitioner::query()->where('name', 'Second')->firstOrFail());

        // Passage à l'offre supérieure.
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'business'])->save());

        ($this->as)()->post("/configuration/praticiens/{$second->id}/activer")->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Practitioner::query()->find($second->id)->status))->toBe('active')
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.activated')->count()))->toBe(1);
    });

    it('libère une place quand un praticien est supprimé', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->add)(['name' => 'Premier']);
        $first = ($this->first)();

        ($this->as)()->delete("/configuration/praticiens/{$first->id}");
        ($this->add)(['name' => 'Remplaçant'])->assertSessionHas('status');

        expect($this->asOwner(fn () => Practitioner::query()->where('name', 'Remplaçant')->value('status')))->toBe('active');
    });
});

describe('modification et suppression', function (): void {
    it('modifie le praticien et ses services, et trace les changements', function (): void {
        ($this->add)();
        $practitioner = ($this->first)();

        ($this->as)()->put("/configuration/praticiens/{$practitioner->id}", practitionerPayload([$this->serviceIds[0]], ['name' => 'Julie M.', 'color' => '#7C3AED']))
            ->assertSessionHasNoErrors();

        $fresh = $this->asOwner(fn () => Practitioner::query()->find($practitioner->id));
        expect($fresh->name)->toBe('Julie M.')->and($fresh->color)->toBe('#7C3AED');
        expect($this->asOwner(fn () => DB::table('practitioner_services')->where('practitioner_id', $practitioner->id)->pluck('service_id')->all()))->toBe([$this->serviceIds[0]]);

        $diff = json_decode($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.updated')->value('diff')), true);
        expect(array_keys($diff))->toEqualCanonicalizing(['name', 'color', 'services']);
    });

    it('n\'écrit pas d\'audit quand rien ne change', function (): void {
        ($this->add)();
        $practitioner = ($this->first)();

        ($this->as)()->put("/configuration/praticiens/{$practitioner->id}", practitionerPayload($this->serviceIds));

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.updated')->count()))->toBe(0);
    });

    it('supprime logiquement et trace', function (): void {
        ($this->add)();
        $practitioner = ($this->first)();

        ($this->as)()->delete("/configuration/praticiens/{$practitioner->id}")->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(0)
            ->and($this->asOwner(fn () => DB::table('practitioners')->where('id', $practitioner->id)->value('deleted_at')))->not->toBeNull()
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.deleted')->count()))->toBe(1);
    });
});

describe('planning', function (): void {
    beforeEach(function (): void {
        ($this->add)(['copy_hours' => false]);
        $this->practitioner = ($this->first)();
        $this->put = fn (array $body, ?Location $site = null) => ($this->as)()->put("/configuration/praticiens/{$this->practitioner->id}/horaires/".($site ?? $this->main)->id, $body);
    });

    it('enregistre le planning et le remplace à chaque enregistrement', function (): void {
        ($this->put)(scheduleWeek())->assertSessionHasNoErrors();
        expect($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(5);

        ($this->put)(scheduleWeek([6], [['start' => '10:00', 'end' => '14:00']]));
        $rows = $this->asOwner(fn () => PractitionerSchedule::query()->get());
        expect($rows)->toHaveCount(1)->and($rows[0]->weekday)->toBe(6);
    });

    it('autorise un planning vide (praticien en congé)', function (): void {
        ($this->put)(scheduleWeek());
        ($this->put)(scheduleWeek([], []))->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(0);
    });

    it('autorise un planning hors des horaires d\'ouverture (l\'intersection est calculée à la réservation)', function (): void {
        ($this->put)(scheduleWeek([1], [['start' => '06:00', 'end' => '22:00']]))->assertSessionHasNoErrors();
    });

    it('refuse les plages invalides', function (array $ranges, string $field): void {
        ($this->put)(scheduleWeek([1], $ranges))->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(0);
    })->with([
        'fin avant le début' => [[['start' => '17:00', 'end' => '09:00']], 'hours.0.ranges.0.end'],
        'plages qui se chevauchent' => [[['start' => '09:00', 'end' => '13:00'], ['start' => '12:00', 'end' => '17:00']], 'hours.0.ranges.1.start'],
        'format invalide' => [[['start' => '9h', 'end' => '17:00']], 'hours.0.ranges.0.start'],
    ]);

    it('empêche d\'être sur deux sites à la même heure', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Deuxième']));
        ($this->put)(scheduleWeek([1], [['start' => '09:00', 'end' => '13:00']]));

        ($this->put)(scheduleWeek([1], [['start' => '12:00', 'end' => '17:00']]), $second)
            ->assertSessionHasErrors(['hours' => 'Ces horaires chevauchent ceux du même praticien sur un autre site : un praticien ne peut pas être à deux endroits à la fois.']);

        // Sans chevauchement, les deux sites sont acceptés.
        ($this->put)(scheduleWeek([1], [['start' => '13:00', 'end' => '17:00']]), $second)->assertSessionHasNoErrors();
        expect($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(2);
    });

    it('trace la modification du planning', function (): void {
        ($this->put)(scheduleWeek());

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.schedule_updated')->count()))->toBe(1);
    });

    it('répond 404 pour le site ou le praticien d\'un autre client', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');
        $foreignSite = $this->asOwner(fn () => Location::query()->where('org_id', $other->id)->firstOrFail());

        ($this->put)(scheduleWeek(), $foreignSite)->assertNotFound();
    });

    it('rend le praticien réservable une fois actif avec un service et un planning', function (): void {
        $manager = app(PractitionerManager::class);
        $tenant = app(TenantContext::class);

        expect($tenant->run($this->org->id, fn () => $manager->isBookable($this->practitioner)))->toBeFalse(); // pas de planning

        ($this->put)(scheduleWeek());

        // Le contexte est vidé à la fin de chaque requête : on le repose le temps de la vérification.
        expect($tenant->run($this->org->id, fn () => $manager->isBookable($this->practitioner->refresh())))->toBeTrue();
    });
});

describe('fin de l\'étape', function (): void {
    it('exige un praticien réservable', function (): void {
        ($this->as)()->post('/configuration/assistant/praticiens/continuer')->assertSessionHasErrors('practitioner');

        // Un praticien sans planning n'est pas réservable.
        ($this->add)(['copy_hours' => false]);
        ($this->as)()->post('/configuration/assistant/praticiens/continuer')->assertSessionHasErrors('practitioner');
        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('practitioners')))->toBeFalse();
    });

    it('ne compte pas un brouillon', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->add)(['name' => 'Premier', 'copy_hours' => false]);
        ($this->add)(['name' => 'Brouillon']);

        ($this->as)()->post('/configuration/assistant/praticiens/continuer')->assertSessionHasErrors('practitioner');
    });

    it('marque l\'étape faite et ouvre la suivante', function (): void {
        ($this->add)();

        ($this->as)()->post('/configuration/assistant/praticiens/continuer')->assertRedirect('/configuration/assistant/politiques');

        expect($this->asOwner(fn () => OnboardingProgress::query()->find($this->org->id)->isDone('practitioners')))->toBeTrue();
    });
});

describe('rôles et isolation', function (): void {
    it('est réservé au propriétaire', function (string $role): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: "{$role}@example.com", role: $role);
        $this->signedIn($user, $org);

        $this->post('/configuration/praticiens', practitionerPayload([]))->assertForbidden();
        $this->get('/configuration/assistant/praticiens')->assertForbidden();
        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(0);
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('refuse toute écriture quand le compte est suspendu', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->add)()->assertRedirect('/compte');

        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(0);
    });

    it('ne montre ni ne modifie les praticiens d\'un autre client', function (): void {
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(['signup_plan' => 'business'], email: 'autre@example.com');
        $this->signedIn($otherOwner, $otherOrg)->post('/configuration/praticiens', practitionerPayload([], ['name' => 'Concurrent']));
        $foreign = $this->asOwner(fn () => Practitioner::query()->where('name', 'Concurrent')->firstOrFail());

        ($this->add)();
        ($this->as)()->get('/configuration/assistant/praticiens')->assertInertia(fn (Assert $page) => $page->has('practitioners', 1)->where('practitioners.0.name', 'Julie Martin'));

        ($this->as)()->put("/configuration/praticiens/{$foreign->id}", practitionerPayload([], ['name' => 'Piraté']))->assertNotFound();
        ($this->as)()->delete("/configuration/praticiens/{$foreign->id}")->assertNotFound();
        ($this->as)()->post("/configuration/praticiens/{$foreign->id}/activer")->assertNotFound();

        expect($this->asOwner(fn () => Practitioner::query()->find($foreign->id)->name))->toBe('Concurrent');
    });

    it('ne compte pas les praticiens d\'un autre client dans la limite', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(['signup_plan' => 'basic'], email: 'autre@example.com');
        $this->signedIn($otherOwner, $otherOrg)->post('/configuration/praticiens', practitionerPayload([]));

        ($this->add)()->assertSessionHas('status');
    });
});

describe('garanties de la base de données', function (): void {
    beforeEach(function (): void {
        ($this->add)(['copy_hours' => false]);
        $this->practitioner = ($this->first)();
    });

    it('interdit deux plages qui se chevauchent pour un praticien, même sur deux sites', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id]));

        $this->asOwner(function () use ($second): void {
            $row = ['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'weekday' => 1];
            PractitionerSchedule::query()->create([...$row, 'location_id' => $this->main->id, 'starts_at' => '09:00', 'ends_at' => '12:00']);
            PractitionerSchedule::query()->create([...$row, 'location_id' => $second->id, 'starts_at' => '11:00', 'ends_at' => '14:00']);
        });
    })->throws(QueryException::class, 'practitioner_schedules_no_overlap');

    it('n\'autorise qu\'un praticien par compte de connexion', function (): void {
        $this->asOwner(function (): void {
            Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'A', 'user_id' => $this->owner->id]);
            Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'B', 'user_id' => $this->owner->id]);
        });
    })->throws(QueryException::class, 'practitioners_one_per_user');

    it('refuse une couleur ou un statut invalide', function (array $attributes, string $constraint): void {
        $this->asOwner(fn () => Practitioner::query()->create(array_merge(['org_id' => $this->org->id, 'name' => 'X'], $attributes)));
    })->with([
        'couleur' => [['color' => 'bleu'], 'practitioners_color_check'],
        'statut' => [['status' => 'inconnu'], 'practitioners_status_check'],
    ])->throws(QueryException::class);

    it('supprime les services et plannings avec le praticien (cascade)', function (): void {
        ($this->as)()->put("/configuration/praticiens/{$this->practitioner->id}/horaires/{$this->main->id}", scheduleWeek());

        $this->asOwner(fn () => DB::table('practitioners')->where('id', $this->practitioner->id)->delete());

        expect($this->asOwner(fn () => DB::table('practitioner_services')->count()))->toBe(0)
            ->and($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(0);
    });

    it('cache les plannings d\'un autre client (RLS)', function (): void {
        ($this->as)()->put("/configuration/praticiens/{$this->practitioner->id}/horaires/{$this->main->id}", scheduleWeek());
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');

        app(TenantContext::class)->set($other->id);

        expect(PractitionerSchedule::query()->count())->toBe(0)->and(Practitioner::query()->count())->toBe(0);
    });
});
