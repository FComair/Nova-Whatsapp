<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Services\SettingsSearchIndex;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Recherche dans les Réglages : l'index multilingue est filtré selon le rôle, comme le menu.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore']);

    $this->memberOf = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });

    $this->ids = fn (?string $role): array => array_column(app(SettingsSearchIndex::class)->forRole($role), 'id');
});

it('donne à chaque entrée ses titres dans les trois langues', function (): void {
    foreach (app(SettingsSearchIndex::class)->forRole('owner') as $entry) {
        foreach (['fr', 'nl', 'en'] as $locale) {
            expect($entry['title'][$locale])->toBeString()->not->toBeEmpty()
                ->and($entry['title'][$locale])->not->toStartWith('ui.');
        }

    }
});

it('traduit titres et mots-clés dans la langue demandée, sans clé brute', function (): void {
    $entries = collect(app(SettingsSearchIndex::class)->forRole('owner'))->keyBy('id');

    expect($entries['calendars']['title'])->toBe(['fr' => __('ui.calendars.title', [], 'fr'), 'nl' => __('ui.calendars.title', [], 'nl'), 'en' => __('ui.calendars.title', [], 'en')])
        ->and($entries['calendars']['keywords']['fr'])->toContain('agenda')
        ->and($entries['calendars']['keywords']['nl'])->toContain('kalender')
        ->and($entries['calendars']['keywords']['en'])->toContain('agenda');

    foreach ($entries as $entry) {
        foreach (['fr', 'nl', 'en'] as $locale) {
            expect($entry['keywords'][$locale])->not->toStartWith('ui.')
                ->and($entry['description'][$locale] ?? '')->not->toMatch('/:[a-z_]+/');
        }
    }
});

it('a des mots-clés dans les trois langues pour chaque entrée', function (): void {
    foreach (app(SettingsSearchIndex::class)->forRole('owner') as $entry) {
        foreach (['fr', 'nl', 'en'] as $locale) {
            expect($entry['keywords'][$locale])->not->toBe('', "mots-clés {$locale} manquants pour {$entry['id']}");
        }
    }
});

it('suit les droits du menu selon le rôle', function (): void {
    $owner = ($this->ids)('owner');
    $manager = ($this->ids)('site_manager');
    $practitioner = ($this->ids)('practitioner');
    $receptionist = ($this->ids)('receptionist');

    expect($owner)->toContain('setup-team', 'profile-logo', 'profile-billing', 'whatsapp', 'data-export', 'calendars', 'security-password');

    foreach ([$manager, $practitioner] as $ids) {
        expect($ids)->toContain('profile', 'calendars', 'security')
            ->not->toContain('setup-team', 'profile-logo', 'profile-billing', 'whatsapp', 'data-export');
    }

    expect($receptionist)->toContain('profile', 'security')
        ->not->toContain('calendars', 'whatsapp', 'data', 'setup-business', 'profile-logo')
        ->and(($this->ids)(null))->toBe([]);
});

it('n\'a que des adresses internes aux Réglages et ne répète aucun identifiant', function (): void {
    $entries = app(SettingsSearchIndex::class)->forRole('owner');
    $ids = array_column($entries, 'id');

    expect($ids)->toBe(array_values(array_unique($ids)));

    foreach ($entries as $entry) {
        expect($entry['href'])->toStartWith('/')->not->toContain('//');
    }
});

describe('route', function (): void {
    it('sert l\'index JSON filtré au propriétaire, sans mise en cache', function (): void {
        $response = $this->signedIn($this->owner, $this->org)->getJson('/reglages/recherche')->assertOk();

        expect(collect($response->json('entries'))->pluck('id')->all())->toContain('setup-team', 'profile-logo');
        expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('ne montre pas aux autres rôles les pages qu\'ils ne peuvent pas ouvrir', function (string $role, array $absent): void {
        $user = ($this->memberOf)($role);

        $ids = collect($this->signedIn($user, $this->org)->getJson('/reglages/recherche')->assertOk()->json('entries'))->pluck('id')->all();

        expect($ids)->toContain('profile')->not->toContain(...$absent);
    })->with([
        'site_manager' => ['site_manager', ['setup-team', 'whatsapp', 'data']],
        'practitioner' => ['practitioner', ['setup-team', 'whatsapp', 'data']],
        'receptionist' => ['receptionist', ['calendars', 'whatsapp', 'data']],
    ]);

    it('exige d\'être connecté', function (): void {
        $this->getJson('/reglages/recherche')->assertUnauthorized();
    });
});
