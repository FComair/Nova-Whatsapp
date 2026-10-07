<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Flows;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Conversation\Services\BookingGateway;
use App\Modules\Conversation\Services\IntentDetector;
use App\Modules\Conversation\Support\Text;
use App\Modules\Conversation\Support\Turn;

/**
 * Annulation : rendez-vous à annuler (choix s'il y en a plusieurs) → « Confirmez-vous l'annulation ? » → annulé ou conservé.
 * L'annulation passe par le cœur de réservation, donc par la fenêtre d'annulation de l'établissement.
 */
final class CancelFlow extends Flow
{
    public const CHOOSE = 'waiting_cancel_appointment_choice';

    public const CONFIRM = 'waiting_cancel_confirmation';

    public const STATES = [self::CHOOSE, self::CONFIRM];

    public function __construct(BookingGateway $gateway, private readonly FaqFlow $faq, private readonly IntentDetector $intents)
    {
        parent::__construct($gateway);
    }

    public function handle(Turn $turn): void
    {
        match ($turn->conv->state) {
            self::CHOOSE => $this->onChoose($turn),
            self::CONFIRM => $this->onConfirm($turn),
            default => $turn->conv->reset(),
        };
    }

    public function start(Turn $turn): void
    {
        $bookings = $this->gateway->upcoming($turn);

        if ($bookings === []) {
            $turn->say('cancel.none');
            $turn->conv->reset();

            return;
        }

        if (count($bookings) === 1) {
            $this->ask($turn, $bookings[0]);

            return;
        }

        $lines = [];

        foreach ($bookings as $index => $booking) {
            $described = $this->describe($turn, $booking);
            $lines[] = $turn->t('cancel.choose_line', [
                'number' => Text::digit($index + 1), 'index' => $index + 1, 'date' => $described['date'],
                'time' => $described['time'], 'service' => $described['service'], 'staff' => $described['staff'],
            ]);
        }

        $turn->conv->reset();
        $turn->conv->set('ids', array_map(static fn (Booking $booking): string => $booking->id, $bookings));
        $turn->say('cancel.choose', ['lines' => implode("\n\n", $lines)]);
        $turn->conv->go(self::CHOOSE, 'cancel_booking');
    }

    private function ask(Turn $turn, Booking $booking): void
    {
        if (! $this->gateway->canChange($turn, $booking)) {
            $turn->say('cancel.too_late', ['business' => $turn->business()]);
            $turn->conv->reset();

            return;
        }

        $described = $this->describe($turn, $booking);
        $turn->conv->reset();
        $turn->conv->set('appointment_id', $booking->id);
        $turn->say('cancel.confirm', [
            'date' => $described['date'], 'start' => $described['time'], 'end' => $described['end'],
            'service' => $described['service'], 'staff' => $described['staff'],
        ]);
        $turn->conv->go(self::CONFIRM, 'cancel_booking');
    }

    private function onChoose(Turn $turn): void
    {
        $ids = $turn->conv->list('ids');
        $number = Text::number($turn->text);
        $booking = $number !== null && $number >= 1 && $number <= count($ids) ? $this->gateway->booking($turn, $ids[$number - 1]) : null;

        if ($booking === null) {
            $turn->say('cancel.invalid_appointment');

            return;
        }

        $this->ask($turn, $booking);
    }

    private function onConfirm(Turn $turn): void
    {
        $booking = $this->gateway->booking($turn, $turn->conv->string('appointment_id'));

        if ($booking === null) {
            $turn->say('cancel.none');
            $turn->conv->reset();

            return;
        }

        if ($this->is($turn, '1', 'cancel_yes')) {
            $this->cancel($turn, $booking);

            return;
        }

        if ($this->is($turn, '2', 'keep')) {
            $this->keep($turn, $booking);

            return;
        }

        // Une question en pleine annulation reçoit sa réponse, puis la confirmation est reposée.
        if ($this->intents->isQuestion($turn->text)) {
            $turn->sayRaw($this->faq->respond($turn, $turn->text));
        } else {
            $turn->say('common.invalid_choice');
        }

        $this->ask($turn, $booking);
    }

    private function keep(Turn $turn, Booking $booking): void
    {
        $described = $this->describe($turn, $booking);
        $turn->say('modify.kept', [
            'date' => $described['date'], 'time' => $described['time'], 'service' => $described['service'],
            'staff' => $described['staff'], 'business' => $turn->business(),
        ]);
        $turn->conv->reset();
    }

    private function cancel(Turn $turn, Booking $booking): void
    {
        $described = $this->describe($turn, $booking);

        try {
            $this->gateway->cancel($turn, $booking);
        } catch (BookingRuleViolation $violation) {
            $turn->say($violation->rule === BookingRuleViolation::CANCELLATION_TOO_LATE ? 'cancel.too_late' : 'cancel.none', ['business' => $turn->business()]);
            $turn->conv->reset();

            return;
        }

        $turn->say('cancel.done', ['date' => $described['date'], 'time' => $described['time'], 'service' => $described['service'], 'staff' => $described['staff']]);
        $turn->conv->reset();
    }
}
