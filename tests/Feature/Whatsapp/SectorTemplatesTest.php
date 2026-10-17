<?php

declare(strict_types=1);

use App\Modules\Admin\Services\MessageTemplateImporter;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\ReminderService;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Support\TemplateCatalog;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/**
 * Envoi avec les modèles de la bibliothèque (docs/39) : seul un modèle du SECTEUR, approuvé par le Super Admin, activé et approuvé par Meta
 * pour l'établissement est utilisé ; sinon l'ancien modèle global reprend la main (transition) ou rien ne part (mode strict).
 * « Maintenant » = mardi 2 juin 2026, 09:00 ; le rendez-vous type est le mercredi 3 juin à 10:00 (Bruxelles).
 */
beforeEach(function (): void {
    $this->bookingWorld();
    $this->asOwner(function (): void {
        Organization::query()->whereKey($this->org->id)->update(['sector' => 'hairdressing']);
        WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token-test']);
        $this->customer->forceFill(['language' => 'fr'])->save();
    });
    $this->org->refresh();
    $this->transport = app(WhatsappTransport::class);

    app(MessageTemplateImporter::class)->import(null);

    $this->template = fn (string $sector, string $type = 'reminder') => DB::connection('pgsql_platform')->table('message_templates')->where('sector_code', $sector)->where('type', $type);
    $this->approve = fn (string $sector, string $type = 'reminder') => ($this->template)($sector, $type)->update(['status' => 'approved']);
    $this->metaApprove = function (string $sector, string $type = 'reminder', string $language = 'fr'): void {
        $name = ($this->template)($sector, $type)->value('meta_name');
        $this->asOwner(fn () => WhatsappTemplate::query()->create(['org_id' => $this->org->id, 'name' => $name, 'language' => $language, 'status' => 'approved']));
    };
    $this->remind = function (): int {
        app(BookingCore::class)->create($this->org, $this->request('2026-06-03 10:00'));
        $this->travelTo(now()->setDate(2026, 6, 2)->setTime(9, 0));

        return app(ReminderService::class)->sendDue($this->org);
    };
    $this->legacy = [
        'account' => 'PN-1', 'to' => '+32470123456', 'template' => 'appointment_reminder', 'language' => 'fr',
        'parameters' => ['org', 'Coupe', 'mercredi 03/06/2026', '10:00'],
    ];
});

it('envoie le modèle approuvé du secteur, avec les variables dans l\'ordre du texte', function (): void {
    ($this->approve)('hairdressing');
    ($this->metaApprove)('hairdressing');

    expect(($this->remind)())->toBe(1);

    // « Bonjour {{customer_name}}, petit rappel : votre rendez-vous chez {{business_name}} ({{service_name}}) a lieu le {{date}} à {{time}}. »
    expect($this->transport->templates)->toBe([[
        'account' => 'PN-1',
        'to' => '+32470123456',
        'template' => 'nova_hairdressing_reminder',
        'language' => 'fr',
        'parameters' => ['Client Test', $this->org->name, 'Coupe', 'mercredi 03/06/2026', '10:00'],
    ]]);
});

it('retombe sur l\'ancien modèle global tant que le secteur n\'a rien d\'approuvé (brouillon, désactivé, pas approuvé par Meta)', function (string $case): void {
    match ($case) {
        'brouillon' => null,
        'désactivé' => (function (): void {
            ($this->approve)('hairdressing');
            ($this->metaApprove)('hairdressing');
            ($this->template)('hairdressing')->update(['is_enabled' => false]);
        })(),
        'refusé' => ($this->template)('hairdressing')->update(['status' => 'rejected']),
        'archivé' => (function (): void {
            ($this->approve)('hairdressing');
            ($this->metaApprove)('hairdressing');
            ($this->template)('hairdressing')->update(['status' => 'archived']);
        })(),
        'pas approuvé par Meta' => ($this->approve)('hairdressing'),
    };

    expect(($this->remind)())->toBe(1);
    expect($this->transport->templates)->toBe([[...$this->legacy, 'parameters' => [$this->org->name, 'Coupe', 'mercredi 03/06/2026', '10:00']]]);
})->with(['brouillon', 'désactivé', 'refusé', 'archivé', 'pas approuvé par Meta']);

it('n\'utilise jamais le modèle approuvé d\'un autre secteur', function (): void {
    ($this->approve)('dental');
    ($this->metaApprove)('dental');

    expect(($this->remind)())->toBe(1);
    expect($this->transport->templates[0]['template'])->toBe('appointment_reminder');
});

it('n\'envoie rien sans modèle approuvé quand le repli est désactivé', function (): void {
    config(['whatsapp.catalog.legacy_fallback' => false]);

    expect(($this->remind)())->toBe(0)->and($this->transport->templates)->toBe([]);
});

it('envoie dès qu\'un modèle du secteur est approuvé, même en mode strict', function (): void {
    config(['whatsapp.catalog.legacy_fallback' => false]);
    ($this->approve)('hairdressing');
    ($this->metaApprove)('hairdressing');

    expect(($this->remind)())->toBe(1);
    expect($this->transport->templates[0]['template'])->toBe('nova_hairdressing_reminder');
});

it('utilise la langue de repli quand le modèle n\'existe pas dans la langue du client', function (): void {
    $this->asOwner(fn () => $this->customer->forceFill(['language' => 'nl'])->save());
    ($this->approve)('hairdressing');
    ($this->metaApprove)('hairdressing', 'reminder', 'fr');
    ($this->metaApprove)('hairdressing', 'reminder', 'nl');
    DB::connection('pgsql_platform')->table('message_template_translations')->where('language', 'nl')
        ->whereIn('template_id', ($this->template)('hairdressing')->pluck('id'))->delete();

    expect(($this->remind)())->toBe(1);
    expect($this->transport->templates[0]['template'])->toBe('nova_hairdressing_reminder')->and($this->transport->templates[0]['language'])->toBe('fr');
});

it('met dans {{customer_name}} le nom de la fiche, le profil WhatsApp seulement à défaut, puis une formule neutre', function (?string $name, ?string $profile, string $expected): void {
    ($this->approve)('hairdressing');
    ($this->metaApprove)('hairdressing');
    $this->asOwner(fn () => $this->customer->forceFill(['name' => $name, 'display_name' => null, 'first_name' => null, 'last_name' => null, 'whatsapp_profile_name' => $profile])->save());

    expect(($this->remind)())->toBe(1);
    expect($this->transport->templates[0]['parameters'][0])->toBe($expected);
})->with([
    'nom de la fiche' => ['Marie Dupont', '✨ Sunshine', 'Marie Dupont'],
    'profil en dernier recours' => [null, '✨ Sunshine', '✨ Sunshine'],
    'aucun nom' => [null, null, 'cher client'],
]);

describe('modèles à soumettre à Meta', function (): void {
    it('liste les quatre modèles globaux tant que le secteur n\'a rien d\'approuvé', function (): void {
        $rows = collect(TemplateCatalog::entries('hairdressing'));

        expect($rows)->toHaveCount(12)->and($rows->pluck('name')->unique()->sort()->values()->all())->toBe(['appointment_cancelled', 'appointment_confirmation', 'appointment_rescheduled', 'appointment_reminder']);
    });

    it('remplace le modèle global par celui du secteur, avec des variables numérotées et des exemples', function (): void {
        ($this->approve)('hairdressing');

        $rows = collect(TemplateCatalog::entries('hairdressing'))->where('key', 'reminder')->keyBy('language');

        expect($rows['fr']['name'])->toBe('nova_hairdressing_reminder')
            ->and($rows['fr']['body'])->toContain('{{1}}')->not->toContain('{{customer_name}}')
            ->and($rows['fr']['examples'])->toBe(['Marie', 'Salon Aurore', 'Coupe', 'jeudi 15/10/2026', '14:00'])
            ->and(collect(TemplateCatalog::entries('hairdressing'))->where('key', 'confirmation')->first()['name'])->toBe('appointment_confirmation');
    });

    it('ajoute les messages non automatiques approuvés du secteur, sans les rendre obligatoires', function (): void {
        ($this->approve)('hairdressing', 'review_request');

        $entry = collect(TemplateCatalog::entries('hairdressing'))->firstWhere('key', 'review_request');

        expect($entry)->not->toBeNull()->and($entry['required'])->toBeFalse()
            ->and(collect(TemplateCatalog::entries('dental'))->firstWhere('key', 'review_request'))->toBeNull();
    });
});
