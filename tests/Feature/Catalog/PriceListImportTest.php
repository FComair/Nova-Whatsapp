<?php

declare(strict_types=1);

use App\Modules\Catalog\Contracts\PriceListReader;
use App\Modules\Catalog\Models\PriceListImport;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Support\OpenAiPriceListReader;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\FakePriceListReader;

uses(AuthScenarios::class);

/**
 * Import du catalogue depuis une liste de prix : lecture, vérification, confirmation, annulation, droits, isolation.
 */
function listPhoto(int $width = 800, int $height = 1000, string $name = 'prices.png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 250, 250, 245));
    $path = tempnam(sys_get_temp_dir(), 'pl').'.png';
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

beforeEach(function (): void {
    Storage::fake('local');
    FakePriceListReader::reset();
    app()->bind(PriceListReader::class, fn () => new FakePriceListReader);

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'locale' => 'fr']);
    RateLimiter::clear('pricelist:'.$this->org->id);

    $this->service = fn (string $name, int $price = 2500, int $duration = 30): Service => $this->asOwner(fn () => Service::query()->create([
        'org_id' => $this->org->id, 'name' => $name, 'duration_minutes' => $duration, 'price_cents' => $price, 'vat_rate' => 6, 'capacity' => 2,
    ]));

    $this->memberOf = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });

    $this->as = fn () => $this->signedIn($this->owner, $this->org);
    $this->upload = fn (?array $files = null) => ($this->as)()->post('/configuration/services/import', ['files' => $files ?? [listPhoto()]]);
    $this->analysed = fn () => $this->asOwner(fn () => PriceListImport::query()->latest()->firstOrFail());

    FakePriceListReader::$lines = [
        ['name' => 'Coupe homme', 'price' => 28, 'category' => 'Cheveux', 'duration_minutes' => 30, 'confidence' => 0.95],
        ['name' => 'Haircut', 'price' => '27,50', 'category' => 'Hair', 'confidence' => 0.9],
        ['name' => 'Couleur', 'price' => 0.55, 'confidence' => 0.5],
        ['name' => 'Shampoing', 'price' => 0, 'confidence' => 0.9],
        ['name' => 'Bon cadeau', 'price' => null, 'confidence' => 0.9],
        ['name' => 'Soin US', 'price' => 30, 'currency' => 'USD', 'confidence' => 0.9],
        ['name' => 'Brushing', 'price' => 22, 'unit' => 'par heure', 'confidence' => 0.9],
        ['name' => 'Brushing', 'price' => 22, 'confidence' => 0.9],
    ];
});

describe('lecture', function (): void {
    it('contrôle les lignes lues, les rapproche des services et garde les photos le temps de l\'import', function (): void {
        ($this->service)('Haircut');

        ($this->upload)([listPhoto(), listPhoto(900, 1200, 'page2.png')])->assertRedirect();

        $import = ($this->analysed)();
        $lines = collect($import->lines)->keyBy('name');

        expect($import->status)->toBe('analysed')
            ->and($import->file_names)->toBe(['prices.png', 'page2.png'])
            ->and(count($import->lines))->toBe(8)
            ->and(Storage::disk('local')->allFiles("pricelists/{$this->org->id}/{$import->id}"))->toHaveCount(2)
            ->and(count(FakePriceListReader::$received))->toBe(2);

        // Prix en centimes, virgule décimale comprise ; durée proposée quand la liste n'en donne pas.
        expect($lines['Haircut']['price_cents'])->toBe(2750)
            ->and($lines['Haircut']['action'])->toBe('update')
            ->and($lines['Haircut']['match']['name'])->toBe('Haircut')
            ->and($lines['Coupe homme']['duration_minutes'])->toBe(30)->and($lines['Coupe homme']['duration_guessed'])->toBeFalse()
            ->and($lines['Couleur']['duration_guessed'])->toBeTrue()->and($lines['Couleur']['duration_minutes'])->toBe(30)
            ->and($lines['Couleur']['warnings'])->toContain('price_low', 'low_confidence', 'no_duration')
            ->and($lines['Shampoing']['warnings'])->toContain('price_zero')
            ->and(collect($import->lines)->where('name', 'Brushing')->first()['warnings'])->toContain('unit_ignored');

        // Écartées d'office, avec la raison : pas de prix, autre devise.
        expect($lines['Bon cadeau']['action'])->toBe('ignore')->and($lines['Bon cadeau']['ignored_reason'])->toBe('no_price')
            ->and($lines['Soin US']['action'])->toBe('ignore')->and($lines['Soin US']['ignored_reason'])->toBe('currency');

        // Le doublon est signalé sur la seconde occurrence.
        $brushings = collect($import->lines)->where('name', 'Brushing')->values();
        expect($brushings[1]['warnings'])->toContain('duplicate');

        // Rien n'est écrit dans le catalogue avant la confirmation.
        expect($this->asOwner(fn () => Service::query()->count()))->toBe(1);
    });

    it('réduit les photos et retire leurs métadonnées avant de les envoyer', function (): void {
        ($this->upload)([listPhoto(3000, 4000)]);

        [$width, $height] = getimagesizefromstring(FakePriceListReader::$received[0]);

        expect(max($width, $height))->toBe(2000)->and(substr(FakePriceListReader::$received[0], 0, 3))->toBe("\xFF\xD8\xFF");
    });

    it('explique pourquoi la lecture échoue, sans rien enregistrer', function (string $case, string $reason): void {
        match ($case) {
            'unavailable' => FakePriceListReader::$available = false,
            'unreadable' => FakePriceListReader::$lines = null,
            'no_prices' => FakePriceListReader::$lines = [['name' => 'Menu du jour', 'price' => null], ['name' => 'Carte']],
            'empty' => FakePriceListReader::$lines = [],
        };

        ($this->upload)()->assertSessionHasErrors(['files', 'reason']);

        expect(session('errors')->get('reason'))->toBe([$reason])
            ->and($this->asOwner(fn () => PriceListImport::query()->count()))->toBe(0)
            ->and(Storage::disk('local')->allFiles('pricelists'))->toBe([]);
    })->with([
        'sans clé' => ['unavailable', 'unavailable'],
        'illisible' => ['unreadable', 'unreadable'],
        'aucun prix' => ['no_prices', 'no_prices'],
        'liste vide' => ['empty', 'no_prices'],
    ]);

    it('refuse les fichiers qui ne sont pas des images lisibles', function (string $case, string $key): void {
        $file = match ($case) {
            'trop petite' => listPhoto(300, 300),
            'texte' => UploadedFile::fake()->createWithContent('liste.png', 'pas une image'),
            'pdf' => UploadedFile::fake()->create('liste.pdf', 100, 'application/pdf'),
            'svg' => UploadedFile::fake()->createWithContent('liste.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'trop lourde' => UploadedFile::fake()->create('lourde.png', 11000, 'image/png'),
        };

        ($this->upload)([$file])->assertSessionHasErrors($key);

        expect($this->asOwner(fn () => PriceListImport::query()->count()))->toBe(0);
    })->with([['trop petite', 'files'], ['texte', 'files'], ['pdf', 'files.0'], ['svg', 'files.0'], ['trop lourde', 'files.0']]);

    it('limite le nombre de pages et le nombre d\'imports par heure', function (): void {
        ($this->upload)(array_map(fn () => listPhoto(), range(1, 11)))->assertSessionHasErrors('files');
        ($this->upload)([])->assertSessionHasErrors('files');

        for ($i = 0; $i < (int) config('pricelist.per_hour'); $i++) {
            ($this->upload)()->assertRedirect();
        }

        ($this->upload)()->assertSessionHasErrors('files');
        expect($this->asOwner(fn () => PriceListImport::query()->count()))->toBe((int) config('pricelist.per_hour'));
    });
});

describe('vérification et import', function (): void {
    beforeEach(function (): void {
        $this->haircut = ($this->service)('Haircut', 2500, 45);
        ($this->upload)();
        $this->import = ($this->analysed)();

        $this->lines = fn (array $override = []): array => $override + [
            'confirm' => true,
            'lines' => [
                ['key' => 'l1', 'action' => 'new', 'name' => 'Coupe homme', 'price_cents' => 2800, 'category' => 'Cheveux', 'duration_minutes' => 30, 'vat_rate' => null],
                ['key' => 'l2', 'action' => 'update', 'name' => 'Haircut', 'price_cents' => 2750, 'category' => 'Hair', 'duration_minutes' => 30, 'service_id' => $this->haircut->id],
                ['key' => 'l5', 'action' => 'ignore'],
            ],
        ];
    });

    it('montre les lignes lues, les services existants et les catégories', function (): void {
        ($this->as)()->get("/configuration/services/import/{$this->import->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/PriceListReview')
            ->has('lines', 8)
            ->has('services', 1)
            ->where('services.0.name', 'Haircut')
            ->where('vatRates', [0, 6, 12, 21]));
    });

    it('ajoute les nouveaux services avec les valeurs par défaut et ne change que le prix des existants', function (): void {
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/apply", ($this->lines)())->assertSessionHasNoErrors()->assertRedirect('/configuration/services/import');

        $new = $this->asOwner(fn () => Service::query()->where('name', 'Coupe homme')->firstOrFail());
        $existing = $this->asOwner(fn () => Service::query()->findOrFail($this->haircut->id));

        expect($new->price_cents)->toBe(2800)->and($new->duration_minutes)->toBe(30)->and($new->vat_rate)->toBe(21)
            ->and($new->capacity)->toBe(1)->and($new->buffer_before_minutes)->toBe(0)->and($new->bookable_online)->toBeTrue()
            ->and($new->category_id)->not->toBeNull();

        // Le prix change ; durée, TVA, capacité ne bougent jamais.
        expect($existing->price_cents)->toBe(2750)->and($existing->duration_minutes)->toBe(45)->and($existing->vat_rate)->toBe(6)->and($existing->capacity)->toBe(2)
            ->and($existing->category_id)->not->toBeNull();

        $done = ($this->analysed)();
        expect($done->status)->toBe('imported')->and([$done->added, $done->updated, $done->ignored])->toBe([1, 1, 1])
            ->and($done->files)->toBeNull()
            ->and(Storage::disk('local')->allFiles('pricelists'))->toBe([])
            ->and($done->result['updated'][0])->toMatchArray(['old_price' => 2500, 'new_price' => 2750]);

        $actions = $this->asOwner(fn () => DB::table('audit_logs')->pluck('action')->all());
        expect($actions)->toContain('catalog.price_list.analysed', 'catalog.price_list.imported', 'service.created', 'service.updated');
    });

    it('exige la confirmation et des lignes valides, sans rien écrire', function (array $override, string $field): void {
        $payload = ($this->lines)($override);

        ($this->as)()->post("/configuration/services/import/{$this->import->id}/apply", $payload)->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => Service::query()->count()))->toBe(1)
            ->and(($this->analysed)()->status)->toBe('analysed');
    })->with([
        'sans confirmation' => [['confirm' => false], 'confirm'],
        'prix négatif' => [['lines' => [['key' => 'l1', 'action' => 'new', 'name' => 'X', 'price_cents' => -5, 'duration_minutes' => 30]]], 'lines.0.price_cents'],
        'durée trop courte' => [['lines' => [['key' => 'l1', 'action' => 'new', 'name' => 'X', 'price_cents' => 500, 'duration_minutes' => 1]]], 'lines.0.duration_minutes'],
        'nom vide' => [['lines' => [['key' => 'l1', 'action' => 'new', 'name' => '', 'price_cents' => 500, 'duration_minutes' => 30]]], 'lines.0.name'],
        'mise à jour sans service' => [['lines' => [['key' => 'l1', 'action' => 'update', 'price_cents' => 500]]], 'lines.0.service_id'],
        'TVA inconnue' => [['lines' => [['key' => 'l1', 'action' => 'new', 'name' => 'X', 'price_cents' => 500, 'duration_minutes' => 30, 'vat_rate' => 7]]], 'lines.0.vat_rate'],
    ]);

    it('ne s\'applique qu\'une fois', function (): void {
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/apply", ($this->lines)())->assertSessionHasNoErrors();
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/apply", ($this->lines)())->assertSessionHasErrors('lines');

        expect($this->asOwner(fn () => Service::query()->where('name', 'Coupe homme')->count()))->toBe(1);
    });

    it('annule une vérification : les photos sont effacées et rien n\'est ajouté', function (): void {
        ($this->as)()->delete("/configuration/services/import/{$this->import->id}")->assertRedirect('/configuration/services/import');

        expect(($this->analysed)()->status)->toBe('cancelled')
            ->and(Storage::disk('local')->allFiles('pricelists'))->toBe([])
            ->and($this->asOwner(fn () => Service::query()->count()))->toBe(1);

        ($this->as)()->get("/configuration/services/import/{$this->import->id}")->assertRedirect('/configuration/services/import');
    });
});

describe('annulation d\'un import', function (): void {
    beforeEach(function (): void {
        $this->haircut = ($this->service)('Haircut', 2500, 45);
        ($this->upload)();
        $this->import = ($this->analysed)();

        ($this->as)()->post("/configuration/services/import/{$this->import->id}/apply", [
            'confirm' => true,
            'lines' => [
                ['key' => 'l1', 'action' => 'new', 'name' => 'Coupe homme', 'price_cents' => 2800, 'duration_minutes' => 30],
                ['key' => 'l3', 'action' => 'new', 'name' => 'Couleur', 'price_cents' => 5500, 'duration_minutes' => 60],
                ['key' => 'l2', 'action' => 'update', 'price_cents' => 2750, 'service_id' => $this->haircut->id],
            ],
        ])->assertSessionHasNoErrors();
    });

    it('retire les services ajoutés et remet l\'ancien prix', function (): void {
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/undo")->assertRedirect('/configuration/services/import');

        expect($this->asOwner(fn () => Service::query()->pluck('name')->all()))->toBe(['Haircut'])
            ->and($this->asOwner(fn () => Service::query()->find($this->haircut->id)->price_cents))->toBe(2500)
            ->and(($this->analysed)()->status)->toBe('undone')
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'catalog.price_list.undone')->count()))->toBe(1);
    });

    it('garde ce qui a servi ou changé depuis l\'import, et le dit', function (): void {
        $this->asOwner(function (): void {
            Service::query()->where('name', 'Couleur')->firstOrFail()->update(['name' => 'Couleur complète']);
            Service::query()->find($this->haircut->id)->update(['price_cents' => 3000]);
        });

        ($this->as)()->post("/configuration/services/import/{$this->import->id}/undo")
            ->assertSessionHas('status', fn (string $status) => str_contains($status, 'Couleur complète') && str_contains($status, 'Haircut'));

        expect($this->asOwner(fn () => Service::query()->orderBy('name')->pluck('name')->all()))->toBe(['Couleur complète', 'Haircut'])
            ->and($this->asOwner(fn () => Service::query()->find($this->haircut->id)->price_cents))->toBe(3000);
    });

    it('ne s\'annule qu\'une fois, et pas après le délai', function (): void {
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/undo");
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/undo")->assertSessionHasErrors('import');

        $this->asOwner(fn () => $this->import->forceFill(['status' => 'imported', 'imported_at' => now()->subDays(8)])->save());
        ($this->as)()->post("/configuration/services/import/{$this->import->id}/undo")->assertSessionHasErrors('import');
    });

    it('liste l\'historique avec la possibilité d\'annuler', function (): void {
        ($this->as)()->get('/configuration/services/import')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Catalog/PriceListImport')
            ->where('available', true)
            ->has('history', 1)
            ->where('history.0.added', 2)->where('history.0.updated', 1)->where('history.0.can_undo', true));
    });

    it('permet de télécharger les lignes importées, sans formule de tableur', function (): void {
        $this->asOwner(fn () => $this->import->forceFill(['result' => array_merge((array) $this->import->result, ['lines' => [
            ['name' => '=HYPERLINK("http://evil")', 'category' => null, 'duration_minutes' => 30, 'price_cents' => 1250, 'vat_rate' => 21, 'action' => 'new'],
        ]])])->save());

        $response = ($this->as)()->get("/configuration/services/import/{$this->import->id}/csv")->assertOk();
        $csv = $response->getContent();

        expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
            ->and((string) $response->headers->get('Content-Disposition'))->toContain('attachment')
            ->and($csv)->toContain('"12,50"')->toContain("\"'=HYPERLINK")->not->toContain('"=HYPERLINK');
    });
});

describe('droits et isolation', function (): void {
    it('est réservé au propriétaire', function (string $role): void {
        $user = ($this->memberOf)($role);
        ($this->upload)();
        $import = ($this->analysed)();

        $this->signedIn($user, $this->org)->get('/configuration/services/import')->assertForbidden();
        $this->signedIn($user, $this->org)->post('/configuration/services/import', ['files' => [listPhoto()]])->assertForbidden();

        $this->signedIn($user, $this->org)->get("/configuration/services/import/{$import->id}")->assertForbidden();
        $this->signedIn($user, $this->org)->post("/configuration/services/import/{$import->id}/apply", ['confirm' => true, 'lines' => []])->assertForbidden();
        $this->signedIn($user, $this->org)->post("/configuration/services/import/{$import->id}/undo")->assertForbidden();
        $this->signedIn($user, $this->org)->delete("/configuration/services/import/{$import->id}")->assertForbidden();
        $this->signedIn($user, $this->org)->get("/configuration/services/import/{$import->id}/csv")->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('exige d\'être connecté', function (): void {
        $this->get('/configuration/services/import')->assertRedirect('/connexion');
    });

    it('ne montre jamais l\'import d\'un autre établissement', function (): void {
        ($this->upload)();
        $import = ($this->analysed)();

        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(['name' => 'Autre salon'], 'autre@example.com');
        $other = fn () => $this->signedIn($otherOwner, $otherOrg);

        $other()->get("/configuration/services/import/{$import->id}")->assertNotFound();
        $other()->post("/configuration/services/import/{$import->id}/apply", ['confirm' => true, 'lines' => [['key' => 'l1', 'action' => 'ignore']]])->assertNotFound();
        $other()->post("/configuration/services/import/{$import->id}/undo")->assertNotFound();
        $other()->delete("/configuration/services/import/{$import->id}")->assertNotFound();
        $other()->get("/configuration/services/import/{$import->id}/csv")->assertNotFound();
        $other()->get('/configuration/services/import')->assertInertia(fn (Assert $page) => $page->where('open', null)->where('history', []));
    });

    it('refuse un identifiant qui n\'est pas un UUID', function (): void {
        ($this->as)()->get('/configuration/services/import/pas-un-uuid')->assertNotFound();
    });
});

describe('lecteur IA', function (): void {
    it('comprend la réponse du modèle, avec ou sans balises de code', function (): void {
        $json = '{"lines":[{"name":"Coupe","price":25.5,"confidence":0.9},{"name":"Soin","price":null},{"price":3},"x"]}';

        expect(OpenAiPriceListReader::parse($json))->toHaveCount(2)
            ->and(OpenAiPriceListReader::parse("```json\n{$json}\n```"))->toHaveCount(2)
            ->and(OpenAiPriceListReader::parse('{"lines":[]}'))->toBe([])
            ->and(OpenAiPriceListReader::parse('pas du json'))->toBeNull()
            ->and(OpenAiPriceListReader::parse('{"autre":1}'))->toBeNull()
            ->and(OpenAiPriceListReader::parse(null))->toBeNull();
    });

    it('envoie les pages au modèle et lit sa réponse, sans clé dans la demande visible', function (): void {
        config(['conversation.ai.api_key' => 'test-key', 'conversation.ai.base_url' => 'https://ai.example/v1']);
        Http::fake(['ai.example/*' => Http::response(['choices' => [['message' => ['content' => '{"lines":[{"name":"Coupe","price":25}]}']]]])]);

        $lines = (new OpenAiPriceListReader)->read(['jpegbytes1', 'jpegbytes2'], 'fr');

        expect($lines)->toBe([['name' => 'Coupe', 'price' => 25]]);

        Http::assertSent(function ($request): bool {
            $content = $request['messages'][1]['content'];

            return $request->url() === 'https://ai.example/v1/chat/completions'
                && $request['response_format']['type'] === 'json_object'
                && count($content) === 3
                && $content[1]['type'] === 'image_url'
                && str_starts_with($content[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });
    });

    it('renvoie null quand le service est indisponible ou refuse la demande', function (): void {
        config(['conversation.ai.api_key' => 'test-key', 'conversation.ai.base_url' => 'https://ai.example/v1']);
        Http::fake(['ai.example/*' => Http::response('erreur', 500)]);

        expect((new OpenAiPriceListReader)->read(['x'], 'fr'))->toBeNull();

        config(['conversation.ai.api_key' => null]);
        expect((new OpenAiPriceListReader)->available())->toBeFalse()->and((new OpenAiPriceListReader)->read(['x'], 'fr'))->toBeNull();
    });
});
