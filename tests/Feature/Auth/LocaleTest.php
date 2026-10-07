<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Langue FR / NL / EN : préférence du compte > session > Accept-Language > défaut (docs/03 §A.5).
 */
it('utilise le français par défaut quand le navigateur n\'indique aucune langue', function (): void {
    $this->withHeader('Accept-Language', '')->get('/connexion')->assertInertia(fn (Assert $page) => $page->where('locale', 'fr')->where('translations.login.title', 'Connexion'));
});

it('suit l\'en-tête Accept-Language', function (string $header, string $locale, string $title): void {
    $this->withHeader('Accept-Language', $header)->get('/connexion')
        ->assertInertia(fn (Assert $page) => $page->where('locale', $locale)->where('translations.login.title', $title));
})->with([
    ['nl-BE,nl;q=0.9,en;q=0.5', 'nl', 'Aanmelden'],
    ['en-GB,en;q=0.9', 'en', 'Sign in'],
    ['de-DE,de;q=0.9', 'fr', 'Connexion'],
]);

it('mémorise le choix de l\'utilisateur en session', function (): void {
    $this->post('/langue', ['locale' => 'nl'])->assertRedirect();

    $this->get('/connexion')->assertInertia(fn (Assert $page) => $page->where('locale', 'nl'));
});

it('le choix explicite l\'emporte sur l\'en-tête du navigateur', function (): void {
    $this->post('/langue', ['locale' => 'en']);

    $this->withHeader('Accept-Language', 'nl')->get('/connexion')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('refuse une langue non prise en charge', function (): void {
    $this->post('/langue', ['locale' => 'de'])->assertSessionHasErrors('locale');
});

it('enregistre la langue dans le profil d\'un utilisateur connecté', function (): void {
    ['user' => $user, 'org' => $org] = $this->activatedAccount();

    $this->withSession([SetTenantContext::SESSION_KEY => $org->id])->actingAs($user)->post('/langue', ['locale' => 'nl']);

    expect($this->asOwner(fn () => User::query()->find($user->id)->locale))->toBe('nl');

    // Reconnexion depuis un autre navigateur : la préférence du compte s'applique.
    $this->flushSession();
    $this->withSession([SetTenantContext::SESSION_KEY => $org->id])->actingAs($user->refresh())->get('/tableau-de-bord')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'nl')->where('translations.dashboard.title', 'Dashboard'));
});

it('a exactement les mêmes clés de traduction dans les trois langues', function (): void {
    $flatten = function (array $array, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($array as $key => $value) {
            is_array($value) ? $keys = [...$keys, ...$flatten($value, "{$prefix}{$key}.")] : $keys[] = "{$prefix}{$key}";
        }

        return $keys;
    };

    foreach (['ui', 'mail', 'admin', 'sector_templates', 'whatsapp', 'billing'] as $group) {
        $fr = $flatten(require lang_path("fr/{$group}.php"));

        foreach (['nl', 'en'] as $locale) {
            $other = $flatten(require lang_path("{$locale}/{$group}.php"));

            expect(array_values(array_diff($fr, $other)))->toBe([], "Clés manquantes en {$locale} ({$group})")
                ->and(array_values(array_diff($other, $fr)))->toBe([], "Clés en trop en {$locale} ({$group})");
        }
    }
});

it('n\'a aucune traduction vide', function (): void {
    foreach (['fr', 'nl', 'en'] as $locale) {
        foreach (['ui', 'mail', 'admin', 'sector_templates', 'whatsapp', 'billing'] as $group) {
            $translations = require lang_path("{$locale}/{$group}.php");

            array_walk_recursive($translations, function ($text, $key) use ($locale, $group): void {
                expect(trim((string) $text))->not->toBe('', "Texte vide : {$locale}/{$group}/{$key}");
            });
        }
    }
});

it('définit en fr, nl et en toutes les clés de traduction littérales utilisées par le code', function (): void {
    $missing = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/\b(?:__|trans)\(\s*\'([a-z_]+(?:\.[a-z0-9_]+)+)\'\s*[,)]/', (string) file_get_contents($file->getPathname()), $found);

        foreach (array_unique($found[1]) as $key) {
            if (str_starts_with($key, 'conversation.prompts.')) {
                continue; // consignes du modèle de langage : volontairement en français seulement
            }

            foreach (['fr', 'nl', 'en'] as $locale) {
                if (! app('translator')->hasForLocale($key, $locale)) {
                    $missing[] = $locale.':'.$key;
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
