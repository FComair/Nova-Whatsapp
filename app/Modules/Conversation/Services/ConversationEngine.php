<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Services;

use App\Modules\Conversation\Flows\BookingFlow;
use App\Modules\Conversation\Flows\CancelFlow;
use App\Modules\Conversation\Flows\FaqFlow;
use App\Modules\Conversation\Flows\MenuFlow;
use App\Modules\Conversation\Flows\ModifyFlow;
use App\Modules\Conversation\Support\ConversationState;
use App\Modules\Conversation\Support\ConversationStore;
use App\Modules\Conversation\Support\Turn;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Chef d'orchestre de la conversation WhatsApp avec le client final (remplace le flux n8n « MASTER ROUTER V2 »).
 *
 * Pour chaque message entrant :
 *  1. l'établissement doit être actif (essai, actif, impayé) ; en pause, le message d'absence est renvoyé ;
 *  2. si la conversation attend une réponse (« waiting_… »), le parcours concerné l'interprète ;
 *  3. sinon l'intention est devinée par règles (mots-clés), puis par le modèle de langage, puis par un menu de clarification ;
 *  4. les réponses sont renvoyées (l'envoi est fait par l'appelant) et l'état est conservé.
 *
 * À exécuter sous le contexte de l'organisation (RLS). Aucun paiement dans la conversation : paiement sur place.
 */
final class ConversationEngine
{
    private const ACTIVE_STATUSES = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE];

    public function __construct(
        private readonly ConversationStore $store,
        private readonly IntentDetector $intents,
        private readonly AiIntentClassifier $ai,
        private readonly MenuFlow $menu,
        private readonly BookingFlow $booking,
        private readonly ModifyFlow $modify,
        private readonly CancelFlow $cancel,
        private readonly FaqFlow $faq,
    ) {}

    /**
     * @param  string  $messageId  identifiant du message entrant : un rejeu du même message ne fait pas avancer le dialogue deux fois
     * @return list<string> les messages à envoyer au client, dans l'ordre
     */
    public function respond(Organization $organization, Customer $customer, string $text, string $messageId): array
    {
        if (! in_array($organization->status, self::ACTIVE_STATUSES, true)) {
            $absence = $organization->status === Organization::STATUS_PAUSED ? ($organization->unavailable_message[$organization->locale] ?? null) : null;

            return $absence === null || $absence === '' ? [] : [$absence];
        }

        $location = Location::query()->where('is_default', true)->first() ?? Location::query()->orderBy('created_at')->first();

        if ($location === null) {
            return []; // aucun site : rien à réserver
        }

        return $this->store->locked($organization->id, $customer->id, function (ConversationState $conversation) use ($organization, $customer, $location, $text, $messageId): array {
            if ($messageId !== '' && $conversation->lastInbound === $messageId) {
                return $conversation->pending; // message rejoué : on reprend là où l'envoi s'est arrêté
            }

            $now = CarbonImmutable::now();

            if ($conversation->handoffUntil !== null) {
                if ($conversation->handoffUntil > $now) {
                    $conversation->lastInbound = $messageId;
                    $conversation->pending = [];

                    return []; // l'équipe a la main
                }

                $conversation->handoffUntil = null;
                $conversation->reset();
            }

            $turn = new Turn($organization, $location, $customer, $conversation, $text, $this->locale($organization, $customer), $now);

            try {
                $this->dispatch($turn);
            } catch (Throwable $e) {
                if (app()->environment('testing')) {
                    throw $e;
                }

                report($e);
                $turn->replies = [$turn->t('common.error')];
                $conversation->reset();
            }

            $conversation->lastInbound = $messageId;
            $conversation->pending = $turn->replies;

            return $turn->replies;
        });
    }

    /** Un message de la liste est parti : il n'est plus à renvoyer en cas de reprise. */
    public function acknowledge(Organization $organization, Customer $customer, int $count = 1): void
    {
        $this->store->locked($organization->id, $customer->id, function (ConversationState $conversation) use ($count): void {
            $conversation->pending = array_slice($conversation->pending, $count);
        });
    }

    /**
     * À appeler quand un rappel est envoyé pour un rendez-vous : la prochaine réponse du client (« 1 » confirmer, « 2 » annuler…)
     * est comprise comme une réponse au rappel.
     */
    public function expectReminderReply(Organization $organization, Customer $customer, string $bookingId): void
    {
        $this->store->locked($organization->id, $customer->id, function (ConversationState $conversation) use ($bookingId): void {
            $conversation->reset();
            $conversation->set('appointment_id', $bookingId);
            $conversation->go(MenuFlow::REMINDER, 'reminder_response');
        });
    }

    private function locale(Organization $organization, Customer $customer): string
    {
        $locales = (array) config('rendivoo.locales');

        return match (true) {
            in_array($customer->language, $locales, true) => (string) $customer->language,
            in_array($organization->locale, $locales, true) => $organization->locale,
            default => 'fr',
        };
    }

    private function dispatch(Turn $turn): void
    {
        $state = $turn->conv->state;

        match (true) {
            in_array($state, BookingFlow::STATES, true) => $this->booking->handle($turn),
            in_array($state, ModifyFlow::STATES, true) => $this->modify->handle($turn),
            in_array($state, CancelFlow::STATES, true) => $this->cancel->handle($turn),
            $state === FaqFlow::QUESTION => $this->faq->handle($turn),
            in_array($state, MenuFlow::STATES, true) => $this->followUp($turn, $this->menu->handle($turn)),
            default => $this->free($turn),
        };
    }

    /** Réponse à un menu : démarre le parcours choisi, ou — si ce n'était pas une réponse au menu — comprend le message comme un message libre. */
    private function followUp(Turn $turn, ?string $next): void
    {
        match ($next) {
            null => $this->free($turn),
            MenuFlow::HANDLED => null,
            default => $this->start($turn, $next),
        };
    }

    /** Message libre : règles, puis modèle de langage, puis clarification. */
    private function free(Turn $turn): void
    {
        $intent = $this->intents->detect($turn->text);

        if ($intent === IntentDetector::UNKNOWN) {
            $intent = $this->ai->classify($turn->text, $turn->conv->state, $turn->conv->intent);
        }

        $this->start($turn, $intent);
    }

    private function start(Turn $turn, string $code): void
    {
        match ($code) {
            IntentDetector::BOOKING => $this->bookOrWarn($turn),
            MenuFlow::NEW_BOOKING => $this->booking->start($turn),
            IntentDetector::MODIFY => $this->modify->start($turn),
            IntentDetector::CANCEL => $this->cancel->start($turn),
            IntentDetector::FAQ => $this->faq->answer($turn, $turn->text),
            MenuFlow::ASK_FAQ => $this->faq->ask($turn),
            IntentDetector::HANDOFF => $this->menu->handoff($turn),
            IntentDetector::GREETING => $this->menu->greeting($turn),
            default => $this->menu->clarify($turn),
        };
    }

    /** Un client qui a déjà un rendez-vous à venir est prévenu avant d'en prendre un autre (modifier / nouveau / annuler). */
    private function bookOrWarn(Turn $turn): void
    {
        if ($this->booking->hasUpcoming($turn)) {
            $this->menu->existingAppointment($turn);

            return;
        }

        $this->booking->start($turn);
    }
}
