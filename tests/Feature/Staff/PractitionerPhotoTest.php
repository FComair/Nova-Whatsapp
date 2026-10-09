<?php

declare(strict_types=1);

use App\Modules\Booking\Services\BookingCore;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Photo de profil des praticiens : envoi, remplacement, retrait, validation, droits, service des images, affichage.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();
    Storage::fake('local');

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}-".uniqid().'@example.com']);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
    $this->photo = fn (string $name = 'portrait.jpg', int $width = 600, int $height = 800): UploadedFile => UploadedFile::fake()->image($name, $width, $height);
    $this->solid = function (string $name, int $r, int $g, int $b, int $side = 400): UploadedFile {
        $image = imagecreatetruecolor($side, $side);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));
        $path = tempnam(sys_get_temp_dir(), 'nova').'.png';
        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    };
    $this->url = fn (?Practitioner $practitioner = null): string => '/configuration/praticiens/'.($practitioner ?? $this->practitioner)->id.'/photo';
    $this->fresh = fn (?Practitioner $practitioner = null): Practitioner => $this->asOwner(fn () => Practitioner::query()->findOrFail(($practitioner ?? $this->practitioner)->id));
    $this->path = fn (int $size, ?Practitioner $practitioner = null): string => "practitioners/{$this->org->id}/".($practitioner ?? $this->practitioner)->id."/{$size}.jpg";
});

describe('envoi, remplacement et retrait', function (): void {
    it('enregistre la photo en deux tailles carrées, rien d\'autre ne change', function (): void {
        $before = ($this->fresh)()->only(['name', 'color', 'status', 'bio', 'position']);

        ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)()])->assertSessionHasNoErrors()->assertSessionHas('status');

        $practitioner = ($this->fresh)();
        expect($practitioner->photo_updated_at)->not->toBeNull()->and($practitioner->only(['name', 'color', 'status', 'bio', 'position']))->toBe($before);

        foreach ([64, 256] as $size) {
            Storage::disk('local')->assertExists(($this->path)($size));
            $info = getimagesizefromstring(Storage::disk('local')->get(($this->path)($size)));
            expect([$info[0], $info[1], $info[2]])->toBe([$size, $size, IMAGETYPE_JPEG]);
        }

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.photo.updated')->exists()))->toBeTrue();
    });

    it('remplace la photo et change sa version', function (): void {
        ($this->as)()->post(($this->url)(), ['photo' => ($this->solid)('a.png', 200, 30, 30)]);
        $first = Storage::disk('local')->get(($this->path)(256));
        $this->travel(5)->seconds();

        ($this->as)()->post(($this->url)(), ['photo' => ($this->solid)('b.png', 20, 60, 200)])->assertSessionHasNoErrors();

        expect(Storage::disk('local')->get(($this->path)(256)))->not->toBe($first);
        expect(($this->fresh)()->photoVersion())->toBeGreaterThan(now()->subSeconds(3)->getTimestamp() - 10);
    });

    it('garde la photo actuelle quand le remplacement est refusé', function (): void {
        ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)()]);
        $bytes = Storage::disk('local')->get(($this->path)(256));
        $version = ($this->fresh)()->photoVersion();
        $this->travel(5)->seconds();

        ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)('petite.jpg', 100, 100)])->assertSessionHasErrors('photo');

        expect(Storage::disk('local')->get(($this->path)(256)))->toBe($bytes)->and(($this->fresh)()->photoVersion())->toBe($version);
    });

    it('supprime la photo et ses fichiers', function (): void {
        ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)()]);

        ($this->as)()->delete(($this->url)())->assertSessionHasNoErrors();

        expect(($this->fresh)()->photo_updated_at)->toBeNull();
        Storage::disk('local')->assertMissing(($this->path)(64));
        Storage::disk('local')->assertMissing(($this->path)(256));
        ($this->as)()->get("/praticiens/{$this->practitioner->id}/photo/64")->assertNotFound();
        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'practitioner.photo.removed')->exists()))->toBeTrue();
    });

    it('accepte une photo choisie à la création du praticien', function (): void {
        ($this->as)()->post('/configuration/praticiens', [
            'name' => 'Nina', 'color' => config('nova.practitioner_colors')[0], 'service_ids' => [], 'photo' => ($this->photo)(),
        ])->assertSessionHasNoErrors();

        $nina = $this->asOwner(fn () => Practitioner::query()->where('name', 'Nina')->firstOrFail());
        expect($nina->photo_updated_at)->not->toBeNull();
        Storage::disk('local')->assertExists(($this->path)(64, $nina));
    });

    it('refuse la création quand la photo est invalide, sans créer le praticien', function (): void {
        ($this->as)()->post('/configuration/praticiens', [
            'name' => 'Nina', 'color' => config('nova.practitioner_colors')[0], 'service_ids' => [], 'photo' => UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('photo');

        expect($this->asOwner(fn () => Practitioner::query()->where('name', 'Nina')->exists()))->toBeFalse();
    });

    it('supprime les fichiers avec le praticien', function (): void {
        ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)()]);

        ($this->as)()->delete('/configuration/praticiens/'.$this->practitioner->id)->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing(($this->path)(256));
    });
});

describe('validation', function (): void {
    it('refuse ce qui n\'est pas une image acceptable', function (callable $file, string $message): void {
        ($this->as)()->post(($this->url)(), ['photo' => $file($this)])->assertSessionHasErrors(['photo' => $message]);

        expect(($this->fresh)()->photo_updated_at)->toBeNull();
    })->with([
        'un PDF' => [fn ($t) => UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'), 'Format non accepté : utilisez une image JPG, PNG ou WebP.'],
        'un GIF' => [fn ($t) => UploadedFile::fake()->image('anim.gif', 400, 400), 'Format non accepté : utilisez une image JPG, PNG ou WebP.'],
        'trop lourde' => [fn ($t) => UploadedFile::fake()->image('lourde.jpg', 400, 400)->size(6000), 'La photo dépasse 5 Mo.'],
        'trop petite' => [fn ($t) => UploadedFile::fake()->image('petite.png', 150, 150), 'La photo est trop petite ou trop grande : au moins 200 × 200 pixels, 6 000 au plus.'],
        'trop grande' => [fn ($t) => UploadedFile::fake()->image('immense.png', 6001, 300), 'La photo est trop petite ou trop grande : au moins 200 × 200 pixels, 6 000 au plus.'],
    ]);

    it('refuse un fichier qui se fait passer pour une image', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'nova');
        file_put_contents($path, '<?php echo "pirate";');

        ($this->as)()->post(($this->url)(), ['photo' => new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true)])->assertSessionHasErrors('photo');
        expect(($this->fresh)()->photo_updated_at)->toBeNull();
    });

    it('exige un fichier', function (): void {
        ($this->as)()->post(($this->url)(), [])->assertSessionHasErrors('photo');
    });
});

describe('droits', function (): void {
    it('réserve l\'envoi pour d\'autres praticiens au propriétaire', function (string $role): void {
        $user = ($this->member)($role);

        ($this->as)($user)->post(($this->url)(), ['photo' => ($this->photo)()])->assertForbidden();
        ($this->as)($user)->delete(($this->url)())->assertForbidden();
        expect(($this->fresh)()->photo_updated_at)->toBeNull();
    })->with(['receptionist', 'site_manager', 'practitioner']);

    it('laisse le praticien changer sa propre photo, jamais celle d\'un autre', function (): void {
        $user = ($this->member)('practitioner', $this->practitioner);
        $other = $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']));

        ($this->as)($user)->post('/mon-profil/photo', ['photo' => ($this->photo)()])->assertSessionHasNoErrors();
        expect(($this->fresh)()->photo_updated_at)->not->toBeNull()->and(($this->fresh)($other)->photo_updated_at)->toBeNull();

        ($this->as)($user)->delete('/mon-profil/photo')->assertSessionHasNoErrors();
        expect(($this->fresh)()->photo_updated_at)->toBeNull();

        ($this->as)($user)->post('/configuration/praticiens/'.$other->id.'/photo', ['photo' => ($this->photo)()])->assertForbidden();
    });
});

describe('service des images', function (): void {
    beforeEach(fn () => ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)()]));

    it('sert la photo aux membres de l\'établissement, avec un cache long', function (): void {
        $user = ($this->member)('receptionist');

        $response = ($this->as)($user)->get("/praticiens/{$this->practitioner->id}/photo/64")->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/jpeg')
            ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
        ($this->as)($user)->get("/praticiens/{$this->practitioner->id}/photo/256")->assertOk();
    });

    it('répond 404 pour une taille inconnue ou un praticien sans photo', function (): void {
        $other = $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']));

        ($this->as)()->get("/praticiens/{$this->practitioner->id}/photo/128")->assertNotFound();
        ($this->as)()->get("/praticiens/{$other->id}/photo/64")->assertNotFound();
    });

    it('ne montre jamais la photo à un autre établissement ni aux visiteurs', function (): void {
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->get("/praticiens/{$this->practitioner->id}/photo/64")->assertNotFound();
        auth()->logout();
        $this->flushSession();
        $this->get("/praticiens/{$this->practitioner->id}/photo/64")->assertRedirect('/connexion');
    });
});

describe('données d\'affichage', function (): void {
    it('transmet la version de la photo au planning, aux filtres clients, aux calendriers et au tableau de bord', function (): void {
        ($this->as)()->post(($this->url)(), ['photo' => ($this->photo)()]);
        $version = ($this->fresh)()->photoVersion();
        $customer = $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => '+32470555123', 'name' => 'Léa']));
        app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00', customer: $customer)));

        // Un rendez-vous plus tard aujourd'hui (le tableau de bord ne liste que la journée).
        app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-01 16:00', key: 'aujourdhui', customer: $customer)));

        ($this->as)()->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->where('practitioners.0.photo_version', $version));
        ($this->as)()->get('/clients')->assertInertia(fn (Assert $page) => $page->where('practitioners.0.photo_version', $version)->where('customers', fn ($customers) => collect($customers)->firstWhere('name', 'Léa')['next']['practitioner_photo'] === $version));
        ($this->as)()->get('/calendriers')->assertInertia(fn (Assert $page) => $page->where('practitioners.0.photo_version', $version));
        ($this->as)()->get('/tableau-de-bord')->assertOk()->assertInertia(fn (Assert $page) => $page->where('summary.appointments.0.practitioner_photo', $version));
    });

    it('envoie null quand le praticien n\'a pas de photo', function (): void {
        ($this->as)()->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->where('practitioners.0.photo_version', null));
    });
});
