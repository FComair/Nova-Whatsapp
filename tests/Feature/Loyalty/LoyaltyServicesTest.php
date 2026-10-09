<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Customers\Services\ConsentRecorder;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use App\Modules\Loyalty\Services\LoyaltyEngine;
use App\Modules\Loyalty\Services\LoyaltyPrograms;
use App\Modules\Loyalty\Services\LoyaltyRedemptions;
use App\Modules\Loyalty\Support\LoyaltyMessages;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;
use Tests\Support\LoyaltyScenarios;

uses(AuthScenarios::class, BookingScenarios::class, LoyaltyScenarios::class);

/**
 * Utilisation des récompenses, cycle de vie du programme (aperçu avant modification, pause, archivage) et messages WhatsApp.
 */
beforeEach(function (): void {
    $this->loyaltyWorld(1);
    $this->redeem = fn ($reward, ?Booking $booking, bool $otherOffer = false) => ($this->in)(fn () => app(LoyaltyRedemptions::class)->redeem($reward, $booking, $this->owner->id, $otherOffer));
    $this->refused = fn ($reward, ?Booking $booking, bool $otherOffer = false): string => ($this->in)(function () use ($reward, $booking, $otherOffer): string {
        try {
            app(LoyaltyRedemptions::class)->redeem($reward, $booking, $this->owner->id, $otherOffer);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return 'accepted';
    });
    // Un rendez-vous cible déjà pris, puis une visite terminée qui fait gagner la récompense (nombre de visites : 1).
    $this->rewardAndTarget = function (array $program = []): array {
        ($this->program)($program);
        $target = ($this->book)();
        ($this->visit)();

        return [($this->rewards)('available')[0], $target];
    };
});

describe('utiliser une récompense', function (): void {
    it('l\'applique à un rendez-vous du client, une seule fois, sans toucher au prix ni à l\'encaissé', function (): void {
        [$reward, $target] = ($this->rewardAndTarget)(['reward_type' => 'percent_discount', 'reward_value' => 20]);

        $used = ($this->redeem)($reward, $target);
        $booking = ($this->in)(fn () => $target->fresh());

        expect($used->status)->toBe('redeemed')->and($used->redeemed_booking_id)->toBe($target->id)->and($used->redeemed_by)->toBe($this->owner->id)->and($used->redeemed_at)->not->toBeNull()
            ->and($used->applied_value_cents)->toBe(500)
            ->and($booking->price_cents)->toBe(2500)->and($booking->amount_collected_cents)->toBe(0)
            ->and(collect(($this->ledger)())->pluck('kind')->contains('reward_redeemed'))->toBeTrue()
            ->and(($this->in)(fn () => AuditLog::query()->where('action', 'loyalty.redeemed')->count()))->toBe(1);

        expect(($this->refused)($reward, $target))->toBe('not_available');
    });

    it('calcule la valeur selon le type de récompense', function (string $type, ?int $value, ?int $max, int $expected): void {
        [$reward] = ($this->rewardAndTarget)(['reward_type' => $type, 'reward_value' => $value, 'reward_max_cents' => $max]);

        expect(app(LoyaltyRedemptions::class)->valueFor($reward, 2500))->toBe($expected);
    })->with([
        'service offert' => ['free_service', null, null, 2500],
        'service offert plafonné' => ['free_service', null, 1500, 1500],
        'pourcentage' => ['percent_discount', 10, null, 250],
        'pourcentage plafonné' => ['percent_discount', 50, 1000, 1000],
        'montant fixe' => ['fixed_amount', 1000, null, 1000],
        'montant fixe jamais plus que le prix' => ['fixed_amount', 9000, null, 2500],
        'récompense personnalisée' => ['custom', null, null, 0],
    ]);

    it('refuse une récompense expirée, même avant le passage de la tâche planifiée', function (): void {
        [$reward, $target] = ($this->rewardAndTarget)(['expiry_days' => 10]);
        $this->travelTo(now()->addDays(11));

        expect(($this->refused)($reward, $target))->toBe('expired');
    });

    it('refuse sans rendez-vous quand le programme en exige un, et l\'accepte sinon', function (): void {
        [$reward] = ($this->rewardAndTarget)(['requires_appointment' => true]);
        expect(($this->refused)($reward, null))->toBe('booking_required');

        $this->asOwner(fn () => DB::table('loyalty_rewards')->where('id', $reward->id)->update(['snapshot' => json_encode([...$reward->snapshot, 'requires_appointment' => false])]));
        $used = ($this->redeem)($reward->fresh(), null);

        expect($used->status)->toBe('redeemed')->and($used->redeemed_booking_id)->toBeNull()->and($used->applied_value_cents)->toBe(0);
    });

    it('refuse le rendez-vous d\'un autre client, un rendez-vous annulé ou absent, et un service non visé', function (): void {
        $other = ($this->customerWith)('+32470111222', 'Autre');
        $couleur = ($this->serviceWith)('Couleur', 6000);
        ($this->program)(['reward_services_mode' => 'selected', 'reward_services' => [$this->service->id]]);
        $mine = ($this->book)();
        $theirs = ($this->book)($other);
        $cancelled = ($this->book)();
        $colour = ($this->book)(null, $couleur);
        ($this->in)(fn () => app(BookingCore::class)->cancel($this->org, $cancelled, Actor::user(null)));
        ($this->visit)();
        [$reward] = ($this->rewards)('available');

        expect(($this->refused)($reward, $theirs))->toBe('booking_mismatch')
            ->and(($this->refused)($reward, $cancelled->fresh()))->toBe('booking_status')
            ->and(($this->refused)($reward, $colour))->toBe('service_not_eligible')
            ->and(($this->refused)($reward, $mine))->toBe('accepted');
    });

    it('à la visite suivante : refuse le rendez-vous qui a fait gagner la récompense ; à tout moment : l\'accepte', function (): void {
        ($this->program)(['redeem_timing' => 'next_visit']);
        $earning = ($this->visit)();
        [$reward] = ($this->rewards)('available');

        expect(($this->refused)($reward, $earning))->toBe('same_visit');

        $this->asOwner(fn () => DB::table('loyalty_rewards')->where('id', $reward->id)->update(['snapshot' => json_encode([...$reward->snapshot, 'redeem_timing' => 'any_time'])]));

        expect(($this->refused)($reward->fresh(), $earning))->toBe('accepted');
    });

    it('refuse une autre offre quand la récompense n\'est pas cumulable, et l\'accepte quand elle l\'est', function (): void {
        [$reward, $target] = ($this->rewardAndTarget)(['combinable' => false]);
        expect(($this->refused)($reward, $target, true))->toBe('not_combinable')->and(($this->refused)($reward, $target, false))->toBe('accepted');

        [$combinable] = [null];
        ($this->in)(fn () => app(LoyaltyPrograms::class)->archive($this->owner->id));
        ($this->program)(['combinable' => true]);
        $second = ($this->book)();
        ($this->visit)();
        $combinable = collect(($this->rewards)('available'))->first();

        expect(($this->refused)($combinable, $second, true))->toBe('accepted');
    });

    it('un rendez-vous ne reçoit qu\'une récompense (le contrôle et la base)', function (): void {
        ($this->program)();
        $target = ($this->book)();
        ($this->visit)();
        ($this->visit)();
        [$first, $second] = ($this->rewards)('available');

        expect(($this->refused)($first, $target))->toBe('accepted')->and(($this->refused)($second, $target))->toBe('already_applied');
    });

    it('annule une utilisation par erreur avec un motif, et la récompense redevient disponible', function (): void {
        [$reward, $target] = ($this->rewardAndTarget)();
        ($this->redeem)($reward, $target);

        expect(fn () => ($this->in)(fn () => app(LoyaltyRedemptions::class)->reverse($reward, $this->owner->id, '  ')))->toThrow(InvalidArgumentException::class, 'reason_required');

        $back = ($this->in)(fn () => app(LoyaltyRedemptions::class)->reverse($reward, $this->owner->id, 'Mauvais client sélectionné'));

        expect($back->status)->toBe('available')->and($back->redeemed_booking_id)->toBeNull()->and($back->applied_value_cents)->toBeNull()
            ->and(collect(($this->ledger)('redemption_reversed'))->first()->reason)->toBe('Mauvais client sélectionné')
            ->and(($this->in)(fn () => AuditLog::query()->where('action', 'loyalty.redemption_reversed')->value('reason')))->toBe('Mauvais client sélectionné');

        // On peut l'utiliser à nouveau, et seulement une fois de plus.
        expect(($this->refused)($back, $target))->toBe('accepted')
            ->and(fn () => ($this->in)(fn () => app(LoyaltyRedemptions::class)->reverse($back->fresh(), $this->owner->id, 'Encore')))->not->toThrow(InvalidArgumentException::class)
            ->and(fn () => ($this->in)(fn () => app(LoyaltyRedemptions::class)->reverse($back->fresh(), $this->owner->id, 'Encore')))->toThrow(InvalidArgumentException::class, 'not_redeemed');
    });

    it('une utilisation annulée après l\'expiration laisse la récompense expirée', function (): void {
        [$reward, $target] = ($this->rewardAndTarget)(['expiry_days' => 5]);
        ($this->redeem)($reward, $target);
        $this->travelTo(now()->addDays(6));

        $back = ($this->in)(fn () => app(LoyaltyRedemptions::class)->reverse($reward, $this->owner->id, 'Erreur de saisie'));

        expect($back->status)->toBe('expired');
    });

    it('la tâche planifiée expire les récompenses échues, une seule fois, et garde les tampons', function (): void {
        ($this->rewardAndTarget)(['expiry_days' => 3]);
        $this->travelTo(now()->addDays(4));

        expect(($this->in)(fn () => app(LoyaltyRedemptions::class)->expireDue()))->toBe(1)->and(($this->in)(fn () => app(LoyaltyRedemptions::class)->expireDue()))->toBe(0)
            ->and(($this->rewards)('expired'))->toHaveCount(1)->and(($this->ledger)('reward_expired'))->toHaveCount(1)->and(($this->member)()->stamps)->toBe(0);
    });

    it('les récompenses déjà obtenues restent utilisables quand le programme est en pause ou archivé', function (string $action): void {
        [$reward, $target] = ($this->rewardAndTarget)();
        ($this->in)(fn () => app(LoyaltyPrograms::class)->{$action}($this->owner->id));

        expect(($this->refused)($reward, $target))->toBe('accepted');
    })->with(['pause', 'archive']);
});

describe('cycle de vie et modification du programme', function (): void {
    it('refuse d\'activer un programme incomplet et dit ce qui manque', function (): void {
        ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...LoyaltyPrograms::defaults(), 'reward_type' => 'percent_discount', 'services_mode' => 'selected'], $this->owner->id, true));

        $problems = ($this->in)(fn () => app(LoyaltyPrograms::class)->problems(LoyaltyProgram::query()->firstOrFail()));

        expect($problems)->toBe(['name', 'reward_description', 'terms', 'reward_value', 'earn_services'])
            ->and(fn () => ($this->in)(fn () => app(LoyaltyPrograms::class)->activate($this->owner->id)))->toThrow(InvalidArgumentException::class, 'invalid')
            ->and(($this->in)(fn () => LoyaltyProgram::query()->value('status')))->toBe('draft');
    });

    it('active un programme valide, et signale les langues non renseignées', function (): void {
        $program = ($this->program)();

        expect($program->status)->toBe('active')->and($program->activated_at)->not->toBeNull()
            ->and(app(LoyaltyPrograms::class)->incompleteLanguages($program))->toBe(['nl', 'en'])
            ->and($program->text('name', 'nl'))->toBe('Fidélité')->and($program->text('name', 'en'))->toBe('Loyalty');
    });

    it('présente l\'effet d\'un changement matériel avant de l\'appliquer, sans rien modifier', function (): void {
        ($this->program)(['required_visits' => 4]);
        ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 3, 'Reprise de carte', $this->owner->id));
        $values = ($this->in)(fn () => app(LoyaltyPrograms::class)->values(LoyaltyProgram::query()->firstOrFail()));

        $outcome = ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...$values, 'required_visits' => 2], $this->owner->id, false));

        expect($outcome['result'])->toBe('confirm')->and($outcome['impact'])->toMatchArray([
            'members' => 1, 'in_progress' => 1, 'required_from' => 4, 'required_to' => 2, 'rewards_now' => 1, 'available_rewards' => 0,
        ])->and(($this->in)(fn () => LoyaltyProgram::query()->value('required_visits')))->toBe(4)->and(($this->member)()->stamps)->toBe(3);
    });

    it('baisser le nombre de visites attribue aussitôt leur récompense à ceux qui ont déjà assez de tampons', function (): void {
        ($this->program)(['required_visits' => 4]);
        ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 3, 'Reprise de carte', $this->owner->id));
        $values = ($this->in)(fn () => app(LoyaltyPrograms::class)->values(LoyaltyProgram::query()->firstOrFail()));

        ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...$values, 'required_visits' => 2], $this->owner->id, true));

        expect(($this->rewards)('available'))->toHaveCount(1)->and(($this->member)()->stamps)->toBe(1)->and(($this->ledgerSum)())->toBe(1);
    });

    it('augmenter le nombre de visites garde la progression et ne retire aucune récompense', function (): void {
        ($this->program)(['required_visits' => 2]);
        ($this->visit)();
        ($this->visit)();
        ($this->visit)();
        $values = ($this->in)(fn () => app(LoyaltyPrograms::class)->values(LoyaltyProgram::query()->firstOrFail()));

        $outcome = ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...$values, 'required_visits' => 10], $this->owner->id, false));
        expect($outcome['impact']['further_away'])->toBe(1)->and($outcome['impact']['available_rewards'])->toBe(1);

        ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...$values, 'required_visits' => 10], $this->owner->id, true));

        expect(($this->rewards)('available'))->toHaveCount(1)->and(($this->member)()->stamps)->toBe(1);
    });

    it('un changement de texte seul ne demande pas de confirmation', function (): void {
        ($this->program)();
        ($this->visit)();
        $values = ($this->in)(fn () => app(LoyaltyPrograms::class)->values(LoyaltyProgram::query()->firstOrFail()));

        $outcome = ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...$values, 'name' => ['fr' => 'Nouveau nom', 'nl' => '', 'en' => '']], $this->owner->id, false));

        expect($outcome['result'])->toBe('saved')->and(($this->in)(fn () => LoyaltyProgram::query()->first()->name['fr']))->toBe('Nouveau nom');
    });

    it('inscrit chaque changement au journal et à l\'audit, avec avant et après', function (): void {
        ($this->program)();
        ($this->visit)();
        $values = ($this->in)(fn () => app(LoyaltyPrograms::class)->values(LoyaltyProgram::query()->firstOrFail()));
        ($this->in)(fn () => app(LoyaltyPrograms::class)->save([...$values, 'require_payment' => true], $this->owner->id, true));
        ($this->in)(fn () => app(LoyaltyPrograms::class)->pause($this->owner->id));

        $entries = ($this->in)(fn () => DB::table('loyalty_ledger')->whereIn('kind', ['config_changed', 'status_changed'])->orderBy('id')->get());

        expect($entries->pluck('kind')->all())->toBe(['config_changed', 'status_changed', 'config_changed', 'status_changed'])
            ->and(json_decode($entries[2]->data, true)['changes']['require_payment'])->toEqual(['from' => false, 'to' => true])
            ->and(json_decode($entries[3]->data, true))->toEqual(['from' => 'active', 'to' => 'paused'])
            ->and(($this->in)(fn () => AuditLog::query()->where('action', 'like', 'loyalty.program_%')->count()))->toBeGreaterThanOrEqual(4);
    });

    it('en pause : plus de tampons ; réactivé : ça reprend et les récompenses dues sont attribuées', function (): void {
        ($this->program)();
        ($this->in)(fn () => app(LoyaltyPrograms::class)->pause($this->owner->id));
        ($this->visit)();
        expect(($this->member)())->toBeNull();

        ($this->in)(fn () => app(LoyaltyPrograms::class)->activate($this->owner->id));
        ($this->visit)();
        expect(($this->rewards)('available'))->toHaveCount(1);
    });

    it('après archivage, un nouveau programme part de zéro sans effacer l\'ancien', function (): void {
        ($this->program)();
        ($this->visit)();
        ($this->in)(fn () => app(LoyaltyPrograms::class)->archive($this->owner->id));

        ($this->program)();
        ($this->visit)();

        $members = ($this->in)(fn () => LoyaltyMember::query()->orderBy('created_at')->get());

        expect($members)->toHaveCount(2)->and($members->pluck('program_id')->unique()->count())->toBe(2)->and($members->pluck('lifetime_stamps')->all())->toBe([1, 1])
            ->and(($this->in)(fn () => LoyaltyReward::query()->count()))->toBe(2);
    });
});

describe('messages WhatsApp', function (): void {
    beforeEach(function (): void {
        $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token-test']));
        $this->transport = app(WhatsappTransport::class);
        $this->consent = fn (string $status = 'granted') => ($this->in)(fn () => app(ConsentRecorder::class)->record($this->customer, 'marketing', $status, 'staff'));
        $this->enable = fn (array $kinds, array $texts = []) => ($this->in)(fn () => app(LoyaltyPrograms::class)->saveMessages(
            collect($kinds)->mapWithKeys(fn (string $kind): array => [$kind => ['enabled' => true, 'texts' => $texts[$kind] ?? []]])->all(), $this->owner->id,
        ));
    });

    it('n\'envoie rien tant que les messages ne sont pas activés', function (): void {
        ($this->program)(['required_visits' => 2]);
        ($this->consent)();

        ($this->visit)();

        expect($this->transport->templates)->toBe([])->and($this->transport->sent)->toBe([]);
    });

    it('n\'envoie rien sans accord marketing du client, ni après un STOP : la participation n\'est pas un consentement', function (): void {
        ($this->program)(['required_visits' => 2]);
        ($this->enable)(LoyaltyMessages::KINDS);

        ($this->visit)();
        expect($this->transport->templates)->toBe([]);

        ($this->consent)();
        ($this->in)(fn () => app(ConsentRecorder::class)->recordStop($this->customer));
        ($this->visit)();

        expect($this->transport->templates)->toBe([])->and(($this->rewards)('available'))->toHaveCount(1);
    });

    it('avec l\'accord du client, envoie progression puis récompense, en modèle approuvé hors fenêtre de 24 h', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['language' => 'nl'])->save());
        ($this->program)(['required_visits' => 2]);
        ($this->enable)(['joined', 'progress', 'reward_earned']);
        ($this->consent)();

        ($this->visit)();
        ($this->visit)();

        $templates = collect($this->transport->templates);

        expect($templates->pluck('template')->all())->toBe(['loyalty_joined', 'loyalty_progress', 'loyalty_reward_earned'])
            ->and($templates[1])->toMatchArray(['to' => '+32470123456', 'language' => 'nl'])
            ->and($templates[1]['parameters'])->toBe([$this->org->name, 'Client', '1/2', 'Une coupe offerte', '-'])
            ->and($templates[2]['parameters'][2])->toBe('0/2')
            ->and(DB::connection('pgsql_owner')->table('whatsapp_usage')->where('org_id', $this->org->id)->where('category', 'marketing')->value('messages'))->toBe(3)
            ->and(($this->ledger)('message_sent'))->toHaveCount(3);
    });

    it('dans la fenêtre de service de 24 h, envoie le texte de l\'établissement avec ses variables', function (): void {
        ($this->program)(['required_visits' => 2]);
        ($this->enable)(['progress'], ['progress' => ['fr' => 'Merci {name} ! {progress} — encore {remaining} avant « {reward} ».']]);
        ($this->consent)();
        $this->asOwner(fn () => WhatsappMessage::query()->create(['org_id' => $this->org->id, 'customer_id' => $this->customer->id, 'direction' => 'in', 'type' => 'text', 'body_enc' => 'Bonjour', 'status' => 'received']));
        $this->travelTo(now()->addDays(4));

        $booking = ($this->book)();
        $this->asOwner(fn () => WhatsappMessage::query()->create(['org_id' => $this->org->id, 'customer_id' => $this->customer->id, 'direction' => 'in', 'type' => 'text', 'body_enc' => 'Merci', 'status' => 'received']));
        ($this->in)(fn () => app(LoyaltyEngine::class)->earn(tap($booking, fn (Booking $b) => $b->forceFill(['status' => 'completed']))));

        expect($this->transport->sent)->toHaveCount(1)->and($this->transport->sent[0]['text'])->toBe('Merci Client ! 1/2 — encore 1 avant « Une coupe offerte ».');
    });

    it('un échec d\'envoi ne bloque jamais le tampon', function (): void {
        ($this->program)(['required_visits' => 2]);
        ($this->enable)(['progress']);
        ($this->consent)();
        $this->transport->failWith = 'meta_down';

        ($this->visit)();

        expect(($this->member)()->stamps)->toBe(1)->and(($this->ledger)('message_sent'))->toBe([]);
    });

    it('rappelle l\'expiration une seule fois, quand le délai approche', function (): void {
        ($this->program)(['expiry_days' => 30, 'expiry_reminder_days' => 7]);
        ($this->enable)(['expiry_reminder']);
        ($this->consent)();
        $this->rewardAt = ($this->visit)();
        $this->travelTo(now()->addDays(10));

        expect(($this->in)(fn () => app(LoyaltyRedemptions::class)->remindExpiring()))->toBe(0);

        $this->travelTo(now()->addDays(15));

        expect(($this->in)(fn () => app(LoyaltyRedemptions::class)->remindExpiring()))->toBe(1)->and(($this->in)(fn () => app(LoyaltyRedemptions::class)->remindExpiring()))->toBe(0)
            ->and($this->transport->templates[0]['template'])->toBe('loyalty_expiry_reminder')->and($this->transport->templates[0]['parameters'][4])->toBe(now()->addDays(5)->format('d/m/Y'));
    });

    it('refuse une variable inconnue plutôt que d\'envoyer « {nom} » tel quel', function (): void {
        ($this->program)();

        expect(fn () => ($this->in)(fn () => app(LoyaltyPrograms::class)->saveMessages(['progress' => ['enabled' => true, 'texts' => ['fr' => 'Bonjour {prenom}']]], $this->owner->id)))
            ->toThrow(InvalidArgumentException::class, 'unknown_variable:progress:prenom');
    });

    it('rend les variables comme l\'aperçu : mêmes noms, mêmes valeurs d\'exemple dans chaque langue', function (): void {
        foreach (['fr', 'nl', 'en'] as $language) {
            $samples = LoyaltyMessages::samples($language);

            expect(array_keys($samples))->toEqualCanonicalizing(LoyaltyMessages::VARIABLES);

            foreach (LoyaltyMessages::KINDS as $kind) {
                $text = LoyaltyMessages::render(LoyaltyMessages::defaultText($kind, $language), $samples);

                expect($text)->not->toContain('{')->and(LoyaltyMessages::unknownVariables(LoyaltyMessages::defaultText($kind, $language)))->toBe([]);
            }
        }
    });
});
