<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappQrCode;
use App\Modules\Whatsapp\Services\QrCodeManager;
use App\Modules\Whatsapp\Services\QrRenderer;
use chillerlan\QRCode\QRCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * QR codes WhatsApp : lien wa.me, images (SVG, PNG, PDF), droits du propriétaire, isolation entre établissements.
 */
function decodeQr(string $png): string
{
    $path = tempnam(sys_get_temp_dir(), 'qr').'.png';
    file_put_contents($path, $png);

    return (string) (new QRCode)->readFromFile($path);
}

beforeEach(function (): void {
    Storage::fake('local');

    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'locale' => 'fr']);

    $this->connect = fn (string $phone = '+32 465 78 85 80', string $status = 'active'): WhatsappAccount => $this->asOwner(fn () => WhatsappAccount::query()->create([
        'org_id' => $this->org->id, 'phone_number_id' => '1234567890'.random_int(100, 999), 'display_phone' => $phone,
        'access_token_enc' => 'token-token-token-token', 'status' => $status,
    ]));

    $this->memberOf = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });

    $this->as = fn () => $this->signedIn($this->owner, $this->org);
});

describe('lien wa.me', function (): void {
    it('garde les chiffres du numéro et encode le message', function (): void {
        expect(QrRenderer::link('+32 465 78 85 80', null))->toBe('https://wa.me/32465788580')
            ->and(QrRenderer::link('+32 (465) 78-85-80', 'Bonjour Salon Aurore'))->toBe('https://wa.me/32465788580?text=Bonjour%20Salon%20Aurore')
            ->and(QrRenderer::link('32465788580', 'Je voudrais un rendez-vous & un café ?'))->toBe('https://wa.me/32465788580?text=Je%20voudrais%20un%20rendez-vous%20%26%20un%20caf%C3%A9%20%3F')
            ->and(QrRenderer::link('+32465788580', '   '))->toBe('https://wa.me/32465788580');
    });

    it('refuse un numéro inutilisable', function (?string $phone): void {
        expect(QrRenderer::link($phone, 'Bonjour'))->toBeNull();
    })->with([null, '', 'abc', '123', '0470123456', '+1234567890123456']);
});

describe('images', function (): void {
    it('dessine un code que l\'on peut relire, avec ou sans logo', function (): void {
        $renderer = new QrRenderer;
        $link = 'https://wa.me/32465788580?text=Bonjour%20Salon%20Aurore';

        $png = $renderer->png($link, '#0B1220');
        [$width, $height] = getimagesizefromstring($png);

        expect([$width, $height])->toBe([1000, 1000])
            ->and(decodeQr($png))->toBe($link);

        $logo = (function (): string {
            $image = imagecreatetruecolor(256, 256);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, 15, 118, 110));
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        })();

        expect(decodeQr($renderer->png($link, '#0F3D91', $logo)))->toBe($link);
    });

    it('produit un SVG valide, sans script', function (): void {
        $svg = (new QrRenderer)->svg('https://wa.me/32465788580', '#0F5132');
        $xml = simplexml_load_string($svg);

        expect($xml)->not->toBeFalse()
            ->and($svg)->toContain('viewBox=')->toContain('fill="#0F5132"')->not->toContain('<script');
    });

    it('produit une affiche PDF A4 ou A6 d\'une page', function (string $format, string $box): void {
        $pdf = (new QrRenderer)->pdf('https://wa.me/32465788580', '#0B1220', 'Salon Aurore & Fils', 'Scannez pour nous écrire sur WhatsApp', $format);

        expect($pdf)->toStartWith('%PDF-1.4')->toContain($box)->toContain('/Count 1')->toEndWith("%%EOF\n")
            ->and(substr_count($pdf, ' re f'))->toBeGreaterThan(300);

        // Table de références : chaque objet est bien à l'endroit annoncé.
        preg_match('/startxref\n(\d+)/', $pdf, $start);
        expect(substr($pdf, (int) $start[1], 4))->toBe('xref');
    })->with([['a4', '[0 0 595 842]'], ['a6', '[0 0 298 420]']]);

    it('refuse une couleur hors de la liste (illisible ou clair sur clair)', function (): void {
        expect(fn () => (new QrRenderer)->svg('https://wa.me/1', '#FFFF00'))->toThrow(InvalidArgumentException::class);
    });
});

describe('page', function (): void {
    it('explique qu\'il faut connecter WhatsApp, sans montrer de code', function (): void {
        ($this->as)()->get('/qr-whatsapp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Account/WhatsappQr')->where('state', 'not_connected')->where('codes', []));

        expect($this->asOwner(fn () => WhatsappQrCode::query()->count()))->toBe(0);
    });

    it('signale un numéro désactivé ou sans numéro lisible', function (): void {
        ($this->connect)('+32 465 78 85 80', 'disabled');
        ($this->as)()->get('/qr-whatsapp')->assertInertia(fn (Assert $page) => $page->where('state', 'paused')->where('codes', []));

        $this->asOwner(fn () => WhatsappAccount::query()->update(['status' => 'active', 'display_phone' => null]));
        ($this->as)()->get('/qr-whatsapp')->assertInertia(fn (Assert $page) => $page->where('state', 'no_number'));
    });

    it('crée le code principal à la première visite, avec le lien wa.me du numéro connecté', function (): void {
        ($this->connect)();

        ($this->as)()->get('/qr-whatsapp')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('state', 'ready')
            ->where('phone', '+32 465 78 85 80')
            ->has('codes', 1)
            ->where('codes.0.is_default', true)
            ->where('codes.0.name', 'Principal')
            ->where('codes.0.message', 'Bonjour Salon Aurore')
            ->where('codes.0.link', 'https://wa.me/32465788580?text=Bonjour%20Salon%20Aurore')
            ->where('selected', fn ($id) => is_string($id)));

        // Une seconde visite ne crée rien de plus.
        ($this->as)()->get('/qr-whatsapp')->assertOk();
        expect($this->asOwner(fn () => WhatsappQrCode::query()->count()))->toBe(1);
    });

    it('est réservée au propriétaire', function (string $role): void {
        ($this->connect)();
        $user = ($this->memberOf)($role);

        $this->signedIn($user, $this->org)->get('/qr-whatsapp')->assertForbidden();
        $this->signedIn($user, $this->org)->post('/qr-whatsapp', ['name' => 'X', 'color' => '#0B1220'])->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('exige d\'être connecté', function (): void {
        $this->get('/qr-whatsapp')->assertRedirect('/connexion');
    });
});

describe('gestion des codes', function (): void {
    beforeEach(function (): void {
        ($this->connect)();
        ($this->as)()->get('/qr-whatsapp');
        $this->main = $this->asOwner(fn () => WhatsappQrCode::query()->where('is_default', true)->firstOrFail());
    });

    it('crée un code, le trace et le sélectionne', function (): void {
        ($this->as)()->post('/qr-whatsapp', ['name' => 'Vitrine', 'message' => 'Je voudrais prendre un rendez-vous', 'color' => '#0F3D91'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $code = $this->asOwner(fn () => WhatsappQrCode::query()->where('name', 'Vitrine')->firstOrFail());

        expect($code->is_default)->toBeFalse()->and($code->color)->toBe('#0F3D91')
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.qr.created')->count()))->toBe(1);
    });

    it('modifie un code et ne trace que ce qui change', function (): void {
        ($this->as)()->put("/qr-whatsapp/{$this->main->id}", ['name' => 'Principal', 'message' => 'Salut', 'color' => '#0B1220'])->assertSessionHasNoErrors();

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.qr.updated')->first());
        $diff = json_decode($log->diff, true);

        expect($diff['after'])->toBe(['message' => 'Salut'])
            ->and($this->asOwner(fn () => WhatsappQrCode::query()->find($this->main->id)->message))->toBe('Salut');

        // Aucun changement : aucune nouvelle ligne d'audit.
        ($this->as)()->put("/qr-whatsapp/{$this->main->id}", ['name' => 'Principal', 'message' => 'Salut', 'color' => '#0B1220']);
        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.qr.updated')->count()))->toBe(1);
    });

    it('refuse un nom vide, un message trop long et une couleur hors liste', function (array $data, string $field): void {
        ($this->as)()->post('/qr-whatsapp', $data + ['name' => 'Test', 'color' => '#0B1220'])->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => WhatsappQrCode::query()->count()))->toBe(1);
    })->with([
        'nom vide' => [['name' => ''], 'name'],
        'nom trop long' => [['name' => str_repeat('a', 61)], 'name'],
        'message trop long' => [['message' => str_repeat('a', 201)], 'message'],
        'couleur claire' => [['color' => '#FFFF00'], 'color'],
        'couleur inventée' => [['color' => 'red'], 'color'],
    ]);

    it('limite le nombre de codes', function (): void {
        for ($i = 1; $i < QrCodeManager::MAX_CODES; $i++) {
            ($this->as)()->post('/qr-whatsapp', ['name' => "Code {$i}", 'color' => '#0B1220'])->assertSessionHasNoErrors();
        }

        ($this->as)()->post('/qr-whatsapp', ['name' => 'Un de trop', 'color' => '#0B1220'])->assertSessionHasErrors('name');
        expect($this->asOwner(fn () => WhatsappQrCode::query()->count()))->toBe(QrCodeManager::MAX_CODES);
    });

    it('supprime un code mais jamais le principal', function (): void {
        ($this->as)()->post('/qr-whatsapp', ['name' => 'Reçus', 'color' => '#0B1220']);
        $extra = $this->asOwner(fn () => WhatsappQrCode::query()->where('name', 'Reçus')->firstOrFail());

        ($this->as)()->delete("/qr-whatsapp/{$extra->id}")->assertRedirect();
        ($this->as)()->delete("/qr-whatsapp/{$this->main->id}")->assertSessionHasErrors('name');

        expect($this->asOwner(fn () => WhatsappQrCode::query()->pluck('id')->all()))->toBe([$this->main->id])
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'whatsapp.qr.deleted')->count()))->toBe(1);
    });

    it('n\'affiche le logo que si l\'établissement en a un', function (): void {
        ($this->as)()->put("/qr-whatsapp/{$this->main->id}", ['name' => 'Principal', 'message' => 'Salut', 'color' => '#0B1220', 'show_logo' => true])
            ->assertSessionHasErrors('show_logo');

        $image = imagecreatetruecolor(300, 300);
        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($image, $path);
        ($this->as)()->post('/reglages/profil/logo', ['logo' => new UploadedFile($path, 'logo.png', 'image/png', null, true)]);

        ($this->as)()->put("/qr-whatsapp/{$this->main->id}", ['name' => 'Principal', 'message' => 'Salut', 'color' => '#0B1220', 'show_logo' => true])
            ->assertSessionHasNoErrors();

        $png = ($this->as)()->get("/qr-whatsapp/{$this->main->id}/png")->assertOk()->getContent();
        expect(decodeQr((string) $png))->toBe('https://wa.me/32465788580?text=Salut');
    });
});

describe('téléchargements', function (): void {
    beforeEach(function (): void {
        ($this->connect)();
        ($this->as)()->get('/qr-whatsapp');
        $this->main = $this->asOwner(fn () => WhatsappQrCode::query()->where('is_default', true)->firstOrFail());
    });

    it('sert PNG, SVG et PDF avec le bon type, en pièce jointe sur demande', function (string $format, string $type): void {
        $response = ($this->as)()->get("/qr-whatsapp/{$this->main->id}/{$format}?download=1")->assertOk();

        expect($response->headers->get('Content-Type'))->toStartWith($type)
            ->and((string) $response->headers->get('Content-Disposition'))->toContain('attachment')->toContain("qr-whatsapp-principal.{$format}")
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    })->with([['png', 'image/png'], ['svg', 'image/svg+xml'], ['pdf', 'application/pdf']]);

    it('encadre l\'affichage direct du SVG', function (): void {
        $response = ($this->as)()->get("/qr-whatsapp/{$this->main->id}/svg")->assertOk();

        expect((string) $response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")
            ->and((string) $response->headers->get('Content-Disposition'))->toBe('');
    });

    it('refuse un format inconnu et un identifiant invalide', function (): void {
        ($this->as)()->get("/qr-whatsapp/{$this->main->id}/gif")->assertNotFound();
        ($this->as)()->get('/qr-whatsapp/pas-un-uuid/png')->assertNotFound();
    });

    it('est réservé au propriétaire', function (): void {
        $user = ($this->memberOf)('receptionist');

        $this->signedIn($user, $this->org)->get("/qr-whatsapp/{$this->main->id}/png")->assertForbidden();
    });

    it('ne sert jamais le code d\'un autre établissement', function (): void {
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(['name' => 'Autre salon'], 'autre@example.com');
        $this->asOwner(fn () => WhatsappAccount::query()->create([
            'org_id' => $otherOrg->id, 'phone_number_id' => '999888777666', 'display_phone' => '+33 6 12 34 56 78',
            'access_token_enc' => 'token-token-token-token', 'status' => 'active',
        ]));

        $this->signedIn($otherOwner, $otherOrg)->get("/qr-whatsapp/{$this->main->id}/png")->assertNotFound();
        $this->signedIn($otherOwner, $otherOrg)->put("/qr-whatsapp/{$this->main->id}", ['name' => 'Piraté', 'color' => '#0B1220'])->assertNotFound();
        $this->signedIn($otherOwner, $otherOrg)->delete("/qr-whatsapp/{$this->main->id}")->assertNotFound();

        expect($this->asOwner(fn () => WhatsappQrCode::query()->find($this->main->id)->name))->toBe('Principal');
    });

    it('répond 409 quand WhatsApp n\'est plus utilisable', function (): void {
        $this->asOwner(fn () => WhatsappAccount::query()->update(['status' => 'disabled']));

        ($this->as)()->get("/qr-whatsapp/{$this->main->id}/png")->assertStatus(409);
    });
});
