<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Flows;

use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Conversation\Services\BookingGateway;
use App\Modules\Conversation\Support\Keywords;
use App\Modules\Conversation\Support\PageView;
use App\Modules\Conversation\Support\Text;
use App\Modules\Conversation\Support\Turn;
use App\Modules\Staff\Models\Practitioner;

/**
 * Éléments communs aux parcours : menus numérotés paginés, récapitulatif, créneaux, messages « créneau pris ».
 *
 * Le contexte de la conversation porte l'élément en cours de choix (même clés pour une réservation en cours de saisie et pour
 * un rendez-vous existant en modification) : service_id, practitioner_id (null = « aucune préférence »), date (AAAA-MM-JJ, heure du site),
 * time (HH:MM), name, email, email_skipped, attempt, mode (`draft` | `existing`), appointment_id.
 */
abstract class Flow
{
    public const DRAFT = 'draft';

    public const EXISTING = 'existing';

    public function __construct(protected readonly BookingGateway $gateway) {}

    /** Le message du client est ce numéro, ou l'un des mots des groupes indiqués (comparaison sans accents ni casse). */
    protected function is(Turn $turn, string $number, string ...$groups): bool
    {
        $message = $turn->message();

        if ($message === $number) {
            return true;
        }

        foreach ($groups as $group) {
            if (Keywords::equals($message, $group)) {
                return true;
            }
        }

        return false;
    }

    protected function size(): int
    {
        return max(1, (int) config('conversation.page_size'));
    }

    /**
     * Les lignes d'un menu : « 1️⃣ libellé », puis (après une ligne vide) « voir plus », « page précédente » et les choix supplémentaires.
     *
     * @param  list<string>  $labels
     * @param  list<string>  $extras
     */
    protected function lines(PageView $view, array $labels, string $more = '', string $previous = '', array $extras = []): string
    {
        $lines = [];

        foreach ($labels as $index => $label) {
            $lines[] = Text::digit($index + 1).' '.$label;
        }

        $tail = [];

        if ($view->more !== null) {
            $tail[] = Text::digit($view->more).' '.$more;
        }

        if ($view->previous !== null) {
            $tail[] = Text::digit($view->previous).' '.$previous;
        }

        foreach ($extras as $index => $label) {
            $tail[] = Text::digit($view->extraStart + $index).' '.$label;
        }

        return implode("\n", $lines).($tail === [] ? '' : "\n\n".implode("\n", $tail));
    }

    /** « Coupe - 30 min - 25 € » (ligne d'un menu de services). */
    protected function serviceLabel(Turn $turn, Service $service): string
    {
        return $service->name.' - '.$service->duration_minutes.' min - '.$turn->price($service->price_cents);
    }

    /**
     * Les créneaux d'une page, un par ligne, avec « voir plus » et « page précédente ».
     *
     * @param  list<string>  $times
     */
    protected function timeLines(Turn $turn, array $times, int $page): string
    {
        $view = PageView::of($times, $page, $this->size());

        return $this->lines($view, $view->items, $turn->t('common.more_times'), $turn->t('common.previous_page'));
    }

    /**
     * Ce que le client a tapé dans un menu de créneaux : un numéro, la suite, le retour, ou une heure exacte proposée.
     *
     * @param  list<string>  $times
     * @return array{0: 'select'|'more'|'previous'|'invalid', 1: string|int}
     */
    protected function resolveTime(Turn $turn, array $times, int $page): array
    {
        $view = PageView::of($times, $page, $this->size());
        [$type, $index] = $view->resolve($turn->text);

        if ($type === 'select') {
            return ['select', $view->items[$index]];
        }

        if ($type === 'more' || $type === 'previous') {
            return [$type, $index];
        }

        $typed = Text::time($turn->text);

        return $typed !== null && in_array($typed, $times, true) ? ['select', $typed] : ['invalid', 0];
    }

    /**
     * Le rendez-vous d'un client, prêt à être affiché.
     *
     * @return array{service: string, staff: string, date: string, time: string, end: string, duration: int, price: int, iso: string}
     */
    protected function describe(Turn $turn, Booking $booking): array
    {
        $service = $this->gateway->service($booking->service_id);
        $staff = $this->gateway->practitioner($booking->practitioner_id);
        $start = $turn->local($booking->starts_at);

        return [
            'service' => $service->name ?? '',
            'staff' => $staff->name ?? '',
            'date' => $turn->longDate($start),
            'time' => $start->format('H:i'),
            'end' => $turn->local($booking->ends_at)->format('H:i'),
            'duration' => (int) $start->diffInMinutes($booking->ends_at, true),
            'price' => $booking->price_cents,
            'iso' => $start->toDateString(),
        ];
    }

    /** Le praticien choisi dans le contexte (null : aucune préférence). */
    protected function chosenPractitioner(Turn $turn): ?Practitioner
    {
        return $this->gateway->practitioner($turn->conv->string('practitioner_id'));
    }

    protected function chosenService(Turn $turn): ?Service
    {
        return $this->gateway->service($turn->conv->string('service_id'));
    }

    /** Le libellé du praticien : son nom, ou « Aucune préférence ». */
    protected function staffLabel(Turn $turn): string
    {
        return $this->chosenPractitioner($turn)->name ?? $turn->t('common.no_preference');
    }

    /** Récapitulatif d'une réservation en cours de saisie, avec le choix Confirmer / Modifier / Annuler. */
    public function summary(Turn $turn): void
    {
        $service = $this->chosenService($turn);
        $practitioner = $this->chosenPractitioner($turn);
        $email = $turn->conv->string('email');

        $turn->say('booking.summary', [
            'name' => $turn->conv->string('name') ?? $turn->t('common.not_provided'),
            'service' => $service->name ?? $turn->t('common.not_provided'),
            'duration' => $service === null ? '-' : $this->gateway->duration($service, $practitioner),
            'price' => $service === null ? '-' : $turn->price($this->gateway->price($service, $practitioner)),
            'staff' => $this->staffLabel($turn),
            'date' => $this->dateLabel($turn),
            'time' => $turn->conv->string('time') ?? $turn->t('common.not_provided'),
            'email' => $email ?? $turn->t('common.no_email'),
        ]);
        $turn->conv->go('waiting_booking_confirmation', 'booking');
    }

    protected function dateLabel(Turn $turn): string
    {
        $date = $turn->conv->string('date');

        return $date === null ? $turn->t('common.not_provided') : $turn->longDateOf($date);
    }

    /**
     * Le créneau choisi n'est plus libre : on propose les horaires qui restent ce jour-là (E10), ou un autre jour.
     *
     * @param  string  $slotState  état d'attente d'un choix de créneau
     * @param  string  $dayState  état d'attente d'un choix de jour
     */
    protected function slotTaken(Turn $turn, string $slotState, string $dayState, ?string $ignoreBookingId = null): void
    {
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');

        if ($service === null || $date === null) {
            $turn->conv->reset();

            return;
        }

        $times = $this->gateway->times($turn, $service, $this->chosenPractitioner($turn), $date, $ignoreBookingId);
        $replace = [
            'name' => $turn->name() === '' ? '' : ' '.$turn->name(),
            'time' => $turn->conv->string('time') ?? '',
            'business' => $turn->business(),
            'service' => $service->name,
            'staff' => $this->staffLabel($turn),
            'date' => $turn->longDateOf($date),
        ];

        if ($times === []) {
            $turn->say('conflict.none', $replace);
            $turn->conv->go($dayState);

            return;
        }

        $turn->conv->set('times', $times);
        $turn->conv->set('time_page', 0);
        $turn->say('conflict.slots', [...$replace, 'slots' => $this->timeLines($turn, $times, 0), 'example' => $times[0]]);
        $turn->conv->go($slotState);
    }
}
