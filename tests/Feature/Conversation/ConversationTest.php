<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Conversation\Contracts\LanguageModel;
use App\Modules\Conversation\Services\ConversationEngine;
use App\Modules\Conversation\Support\DayParser;
use App\Modules\Conversation\Support\Keywords;
use App\Modules\Conversation\Support\PageView;
use App\Modules\Conversation\Support\Text;
use App\Modules\Customers\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

beforeEach(function (): void {
    Cache::flush();
    Keywords::flush();
    $this->bookingWorld();
    $this->engine = app(ConversationEngine::class);
    $this->counter = 0;
    $this->talk = function (string $text, ?string $id = null): string {
        $id ??= 'wamid.'.++$this->counter;

        return implode("\n---\n", $this->engine->respond($this->org, $this->customer, $text, $id));
    };
});

describe('accueil', function (): void {
    it('accueille le client avec le menu principal', function (): void {
        $reply = ($this->talk)('Bonjour');

        expect($reply)->toContain('Bonjour Client Test 👋')
            ->toContain('Bienvenue chez '.$this->org->name)
            ->toContain('1️⃣ Prendre un rendez-vous')
            ->toContain('2️⃣ Poser une question');
    });

    it('propose les 5 choix quand le client a déjà un rendez-vous', function (): void {
        $this->core = app(BookingCore::class);
        $this->core->create($this->org, $this->request('2026-06-05 10:00', source: 'whatsapp', customer: $this->customer));

        $reply = ($this->talk)('Bonjour');

        expect($reply)->toContain('Vous avez un rendez-vous prévu')
            ->toContain('1️⃣ Confirmer ma présence ✅')
            ->toContain('5️⃣ Poser une question');
    });

    it('répond dans la langue du client', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['language' => 'nl'])->save());
        $this->customer->refresh();

        expect(($this->talk)('Hallo'))->toContain('Welkom bij');
    });

    it('propose le menu de clarification quand le message est incompréhensible', function (): void {
        $reply = ($this->talk)('azerty qsdfgh');

        expect($reply)->toContain('Je n’ai pas bien compris votre demande')->toContain('5️⃣ Parler à quelqu’un');
    });

    it('se tait quand l\'établissement est suspendu et répond le message d\'absence en pause', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());
        $this->org->refresh();

        expect(($this->talk)('Bonjour'))->toBe('');

        $this->asOwner(fn () => $this->org->forceFill(['status' => 'paused', 'unavailable_message' => ['fr' => 'Nous reviendrons bientôt.']])->save());
        $this->org->refresh();

        expect(($this->talk)('Bonjour'))->toBe('Nous reviendrons bientôt.');
    });
});

describe('réservation', function (): void {
    it('mène le client du menu à la confirmation du rendez-vous', function (): void {
        expect(($this->talk)('Bonjour'))->toContain('Bienvenue');
        expect(($this->talk)('1'))->toContain('Quel service souhaitez-vous ?')->toContain('1️⃣ Coupe - 30 min - 25 €');
        expect(($this->talk)('1'))->toContain('Quel jour souhaitez-vous prendre rendez-vous ?');

        $slots = ($this->talk)('demain');
        expect($slots)->toContain('Choisissez l\'heure qui vous convient')->toContain('1️⃣ 09:00');

        $name = ($this->talk)('1');
        expect($name)->toContain('Pour qui est ce rendez-vous ?');

        expect(($this->talk)('Marie Dupont'))->toContain('Souhaitez-vous recevoir votre rendez-vous dans votre calendrier ?');

        $summary = ($this->talk)('0');
        expect($summary)->toContain('Veuillez vérifier votre rendez-vous')
            ->toContain('Marie Dupont')->toContain('Coupe')->toContain('Léa')->toContain('09:00')
            ->toContain('1️⃣ Confirmer ✅');

        $done = ($this->talk)('1');
        expect($done)->toContain('Votre rendez-vous est confirmé avec succès')->toContain('09:00 - 09:30')->toContain('25 €');

        $booking = Booking::query()->sole();
        expect($booking->status)->toBe('confirmed')
            ->and($booking->source)->toBe('whatsapp')
            ->and($booking->customer_id)->toBe($this->customer->id)
            ->and($booking->starts_at->equalTo($this->brussels('2026-06-02 09:00')))->toBeTrue();
    });

    it('ne crée jamais deux rendez-vous quand le même message est rejoué', function (): void {
        foreach (['Bonjour', '1', '1', 'demain', '1', 'Marie Dupont', '0'] as $text) {
            ($this->talk)($text);
        }

        $first = ($this->talk)('1', 'wamid.CONFIRM');
        $second = ($this->talk)('1', 'wamid.CONFIRM');

        expect($second)->toBe($first)->and(Booking::query()->count())->toBe(1);
    });

    it('propose les créneaux restants quand celui choisi vient d\'être pris', function (): void {
        foreach (['Bonjour', '1', '1', 'demain', '1', 'Marie Dupont', '0'] as $text) {
            ($this->talk)($text);
        }

        $other = $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => '+32470999999', 'name' => 'Autre']));
        app(BookingCore::class)->create($this->org, $this->request('2026-06-02 09:00', source: 'whatsapp', customer: $other));

        $reply = ($this->talk)('1');

        expect($reply)->toContain('n\'est malheureusement plus disponible')->toContain('1️⃣ 09:30')
            ->and(Booking::query()->where('customer_id', $this->customer->id)->count())->toBe(0);
    });

    it('abandonne la réservation sur « 3 » au récapitulatif', function (): void {
        foreach (['Bonjour', '1', '1', 'demain', '1', 'Marie Dupont', '0'] as $text) {
            ($this->talk)($text);
        }

        expect(($this->talk)('3'))->toContain('Votre demande de rendez-vous a été annulée')
            ->and(Booking::query()->count())->toBe(0);
    });

    it('redemande le jour quand il n\'est pas compris et refuse un choix de service invalide', function (): void {
        ($this->talk)('Bonjour');
        ($this->talk)('1');

        expect(($this->talk)('9'))->toContain('Choix invalide');
        ($this->talk)('1');
        expect(($this->talk)('blabla'))->toContain('Je n\'ai pas compris le jour');
    });

    it('prévient le client qui a déjà un rendez-vous avant d\'en créer un autre', function (): void {
        app(BookingCore::class)->create($this->org, $this->request('2026-06-05 10:00', source: 'whatsapp', customer: $this->customer));

        expect(($this->talk)('Je voudrais prendre un rendez-vous'))->toContain('Vous avez déjà un rendez-vous prévu');
    });
});

describe('annulation et modification', function (): void {
    beforeEach(function (): void {
        $this->existing = app(BookingCore::class)->create($this->org, $this->request('2026-06-05 10:00', source: 'whatsapp', customer: $this->customer));
    });

    it('annule un rendez-vous après confirmation', function (): void {
        expect(($this->talk)('Je veux annuler mon rendez-vous'))->toContain('Confirmation d\'annulation')->toContain('10:00');

        expect(($this->talk)('1'))->toContain('Votre rendez-vous a bien été annulé');
        expect(Booking::query()->find($this->existing->id)->status)->toBe(Booking::CANCELLED_BY_CUSTOMER);
    });

    it('conserve le rendez-vous quand le client répond « 2 »', function (): void {
        ($this->talk)('annuler');

        expect(($this->talk)('2'))->toContain('Votre rendez-vous est conservé')
            ->and(Booking::query()->find($this->existing->id)->status)->toBe('confirmed');
    });

    it('refuse d\'annuler dans le délai interdit par l\'établissement', function (): void {
        $this->asOwner(fn () => DB::table('booking_policies')->updateOrInsert(['org_id' => $this->org->id], ['cancellation_window_hours' => 120]));

        expect(($this->talk)('annuler'))->toContain('ne peut plus être annulé en ligne');
    });

    it('déplace un rendez-vous vers une autre heure', function (): void {
        expect(($this->talk)('Je voudrais modifier mon rendez-vous'))->toContain('Que souhaitez-vous modifier ?');
        ($this->talk)('4'); // l'heure
        $reply = ($this->talk)('3'); // créneaux toutes les 15 min : 09:00, 09:15, 09:30

        expect($reply)->toContain('Parfait')->toContain('Confirmer');
        expect(($this->talk)('1'))->toContain('mis à jour et confirmé');

        $booking = Booking::query()->where('customer_id', $this->customer->id)->where('status', 'confirmed')->sole();
        expect($booking->starts_at->equalTo($this->brussels('2026-06-05 09:30')))->toBeTrue();
    });
});

describe('questions et conseiller', function (): void {
    it('demande la question puis renvoie vers l\'établissement sans modèle de langage', function (): void {
        expect(($this->talk)('Bonjour'))->toContain('Bienvenue');
        expect(($this->talk)('2'))->toContain('Quelle est votre question ?');

        $reply = ($this->talk)('Quels sont vos horaires ?');
        expect($reply)->toContain($this->org->name)->toContain('1️⃣ Prendre un rendez-vous');
    });

    it('répond à une question avec le modèle de langage, à partir des seules données de l\'établissement', function (): void {
        $seen = new ArrayObject;
        app()->bind(LanguageModel::class, fn () => new class($seen) implements LanguageModel
        {
            public function __construct(private ArrayObject $seen) {}

            public function available(): bool
            {
                return true;
            }

            public function complete(string $system, string $user): ?string
            {
                $this->seen[] = $system;

                return 'Nous sommes ouverts de 9 h à 17 h.';
            }
        });
        $this->engine = app(ConversationEngine::class);

        ($this->talk)('Bonjour');
        ($this->talk)('2');
        $reply = ($this->talk)('Quels sont vos horaires ?');

        expect($reply)->toContain('Nous sommes ouverts de 9 h à 17 h.')
            ->and($seen[0])->toContain('lundi : 09:00-17:00')->toContain('Coupe : 30 min, 25 €')->toContain($this->org->name);
    });

    it('classe un message libre avec le modèle de langage seulement au-dessus du seuil de confiance', function (): void {
        $answer = '{"intent":"cancel_booking","confidence":0.5}';
        app()->bind(LanguageModel::class, fn () => new class(fn () => $answer) implements LanguageModel
        {
            public function __construct(private Closure $answer) {}

            public function available(): bool
            {
                return true;
            }

            public function complete(string $system, string $user): ?string
            {
                return ($this->answer)();
            }
        });
        $this->engine = app(ConversationEngine::class);

        expect(($this->talk)('azerty qsdfgh'))->toContain('Je n’ai pas bien compris');
    });

    it('transmet à l\'équipe puis se tait', function (): void {
        expect(($this->talk)('Je voudrais parler à quelqu\'un'))->toContain('Je transmets votre demande');
        expect(($this->talk)('Allô ?'))->toBe('');
    });

    it('reprend la conversation après la période de silence', function (): void {
        ($this->talk)('Je voudrais parler à quelqu\'un');
        $this->travel(13)->hours();

        expect(($this->talk)('Bonjour'))->toContain('Bienvenue');
    });
});

describe('outils', function (): void {
    it('comprend les jours que le client écrit', function (): void {
        $monday = CarbonImmutable::parse('2026-06-01', 'Europe/Brussels');

        expect(DayParser::parse('demain', $monday)?->toDateString())->toBe('2026-06-02')
            ->and(DayParser::parse('Après-demain', $monday)?->toDateString())->toBe('2026-06-03')
            ->and(DayParser::parse('vendredi', $monday)?->toDateString())->toBe('2026-06-05')
            ->and(DayParser::parse('lundi', $monday)?->toDateString())->toBe('2026-06-08')
            ->and(DayParser::parse('12/09', $monday)?->toDateString())->toBe('2026-09-12')
            ->and(DayParser::parse('morgen', $monday)?->toDateString())->toBe('2026-06-02')
            ->and(DayParser::parse('blabla', $monday))->toBeNull();
    });

    it('numérote les menus comme le flux n8n', function (): void {
        $all = array_map('strval', range(1, 7));

        $first = PageView::of($all, 0, 5);
        expect($first->items)->toHaveCount(5)->and($first->more)->toBe(6)->and($first->previous)->toBeNull();

        $second = PageView::of($all, 1, 5);
        expect($second->items)->toBe(['6', '7'])->and($second->more)->toBeNull()->and($second->previous)->toBe(3);

        expect($first->resolve('6'))->toBe(['more', 0])->and($first->resolve('2'))->toBe(['select', 1])->and($first->resolve('9')[0])->toBe('invalid');
    });

    it('normalise les textes', function (): void {
        expect(Text::normalize('  Après-DEMAIN '))->toBe('apres-demain')
            ->and(Text::digit(12))->toBe('1️⃣2️⃣')
            ->and(Text::time('9h30'))->toBe('09:30')
            ->and(Text::isEmail('nom@gmail.com'))->toBeTrue()
            ->and(Text::isEmail('nom@'))->toBeFalse();
    });
});
