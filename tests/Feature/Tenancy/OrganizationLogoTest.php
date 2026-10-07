<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Services\OrganizationLogo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Logo de l'établissement : envoi, remplacement, retrait, service privé par établissement, droits du propriétaire.
 */
function pngFile(int $width, int $height, string $name = 'logo.png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
    $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

beforeEach(function (): void {
    Storage::fake('local');

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore']);

    $this->memberOf = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });

    $this->upload = fn (UploadedFile $file, ?User $as = null) => $this->signedIn($as ?? $this->owner, $this->org)
        ->post('/reglages/profil/logo', ['logo' => $file]);
});

it('enregistre le logo en deux tailles carrées, trace l\'envoi et expose la version', function (): void {
    ($this->upload)(pngFile(600, 400))->assertSessionHasNoErrors()->assertRedirect();

    $path = app(OrganizationLogo::class)->path($this->org->id, 256);
    Storage::disk('local')->assertExists($path);
    Storage::disk('local')->assertExists(app(OrganizationLogo::class)->path($this->org->id, 64));

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([256, 256]);

    expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.logo.updated')->where('org_id', $this->org->id)->count()))->toBe(1);

    $this->signedIn($this->owner, $this->org)->get('/reglages/profil')->assertInertia(fn (Assert $page) => $page
        ->where('auth.organization.logo_version', fn ($version) => is_int($version) && $version > 0));
});

it('remplace le logo existant', function (): void {
    ($this->upload)(pngFile(300, 300));
    ($this->upload)(pngFile(500, 500));

    expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.logo.updated')->count()))->toBe(2);
    expect(Storage::disk('local')->allFiles("logos/{$this->org->id}"))->toHaveCount(2);
});

it('retire le logo : fichiers supprimés, version vide, action tracée', function (): void {
    ($this->upload)(pngFile(300, 300));

    $this->signedIn($this->owner, $this->org)->delete('/reglages/profil/logo')->assertRedirect();

    expect(Storage::disk('local')->allFiles("logos/{$this->org->id}"))->toBe([]);
    expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.logo.removed')->count()))->toBe(1);

    $this->signedIn($this->owner, $this->org)->get('/reglages/profil')->assertInertia(fn (Assert $page) => $page
        ->where('auth.organization.logo_version', null));
});

it('refuse les fichiers non conformes', function (string $case): void {
    $file = match ($case) {
        'trop petit' => pngFile(150, 150),
        'trop lourd' => UploadedFile::fake()->create('big.png', 3000, 'image/png'),
        'svg' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300"><script>alert(1)</script></svg>'),
        'pdf' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
        'texte déguisé' => UploadedFile::fake()->createWithContent('logo.png', 'pas une image'),
    };

    ($this->upload)($file)->assertSessionHasErrors('logo');

    expect(Storage::disk('local')->allFiles('logos'))->toBe([]);
    expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'org.logo.updated')->count()))->toBe(0);
})->with(['trop petit', 'trop lourd', 'svg', 'pdf', 'texte déguisé']);

it('réserve l\'envoi et le retrait au propriétaire', function (string $role): void {
    $user = ($this->memberOf)($role);

    ($this->upload)(pngFile(300, 300), $user)->assertForbidden();
    $this->signedIn($user, $this->org)->delete('/reglages/profil/logo')->assertForbidden();

    expect(Storage::disk('local')->allFiles('logos'))->toBe([]);
})->with(['site_manager', 'practitioner', 'receptionist']);

describe('lecture', function (): void {
    it('sert le logo aux membres, avec un cache long et sans devinette de type', function (): void {
        ($this->upload)(pngFile(300, 300));
        $user = ($this->memberOf)('receptionist');

        $response = $this->signedIn($user, $this->org)->get('/reglages/profil/logo/64')->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('image/png')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('max-age=31536000')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('répond 404 sans logo ou pour une taille inconnue', function (): void {
        $this->signedIn($this->owner, $this->org)->get('/reglages/profil/logo/64')->assertNotFound();

        ($this->upload)(pngFile(300, 300));
        $this->signedIn($this->owner, $this->org)->get('/reglages/profil/logo/999')->assertNotFound();
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/reglages/profil/logo/64')->assertRedirect('/connexion');
    });

    it('ne sert jamais le logo d\'un autre établissement', function (): void {
        ($this->upload)(pngFile(300, 300));
        $mine = Storage::disk('local')->get(app(OrganizationLogo::class)->path($this->org->id, 64));

        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(['name' => 'Autre salon'], 'autre@example.com');

        // L'autre établissement n'a pas de logo : 404, et surtout pas le fichier du premier.
        $this->signedIn($otherOwner, $otherOrg)->get('/reglages/profil/logo/64')->assertNotFound();
        expect($mine)->not->toBe('');
    });
});

it('supprime les fichiers à l\'effacement du compte', function (): void {
    ($this->upload)(pngFile(300, 300));

    app(OrganizationLogo::class)->purge($this->org->id);

    expect(Storage::disk('local')->allFiles("logos/{$this->org->id}"))->toBe([]);
});
