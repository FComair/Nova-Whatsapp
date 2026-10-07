<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Flows;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Services\IntentDetector;
use App\Modules\Conversation\Support\Keywords;
use App\Modules\Conversation\Support\Turn;
use Carbon\CarbonImmutable;

/**
 * Accueil et menus d'aiguillage : salutation (avec ou sans rendez-vous à venir), menu principal, « Vous avez déjà un rendez-vous »,
 * clarification quand l'intention est incertaine, réponse à un rappel, « Parler à quelqu'un ».
 *
 * Les méthodes de réponse renvoient le parcours à démarrer (une intention, NEW_BOOKING ou ASK_FAQ), HANDLED si le message a déjà
 * reçu sa réponse, ou null si le message n'est pas une réponse au menu (le moteur le comprend alors comme un message libre).
 */
final class MenuFlow extends Flow
{
    public const MAIN = 'waiting_main_menu_choice';

    public const EXISTING_BOOKING = 'waiting_existing_booking_choice';

    public const EXISTING_APPOINTMENT = 'waiting_existing_appointment_choice';

    public const CLARIFY = 'waiting_intent_clarification';

    public const REMINDER = 'waiting_reminder_reply';

    public const HANDOFF = 'waiting_human_handoff';

    /** Nouvelle réservation demandée alors qu'un rendez-vous existe déjà : on ne repose pas la question. */
    public const NEW_BOOKING = 'new_booking';

    /** « Poser une question » : on demande la question. */
    public const ASK_FAQ = 'faq_ask';

    public const HANDLED = 'handled';

    public const STATES = [self::MAIN, self::EXISTING_BOOKING, self::EXISTING_APPOINTMENT, self::CLARIFY, self::REMINDER];

    /** Salutation : menu complet si le client a un rendez-vous à venir, sinon « Prendre un rendez-vous / Poser une question ». */
    public function greeting(Turn $turn): void
    {
        $bookings = $this->gateway->upcoming($turn);

        if ($bookings === []) {
            $turn->conv->reset();
            $turn->say('menu.greeting_new', ['spaced' => $turn->spacedName(), 'business' => $turn->business()]);
            $turn->conv->go(self::MAIN, 'greeting');

            return;
        }

        $turn->conv->reset();
        $turn->conv->set('appointment_id', $bookings[0]->id);
        $turn->say('menu.greeting_existing', $this->appointmentData($turn, $bookings[0]) + ['spaced' => $turn->spacedName()]);
        $turn->conv->go(self::EXISTING_BOOKING, 'greeting');
    }

    /** « Vous avez déjà un rendez-vous prévu » : modifier, en prendre un nouveau ou annuler. */
    public function existingAppointment(Turn $turn): void
    {
        $bookings = $this->gateway->upcoming($turn);
        $turn->conv->reset();
        $turn->conv->set('appointment_id', $bookings[0]->id ?? null);
        $turn->say('menu.existing_appointment', $bookings === [] ? [] : $this->appointmentData($turn, $bookings[0]) + ['spaced' => $turn->spacedName()]);
        $turn->conv->go(self::EXISTING_APPOINTMENT, 'booking');
    }

    public function clarify(Turn $turn): void
    {
        $turn->conv->reset();
        $turn->say('menu.clarify');
        $turn->conv->go(self::CLARIFY, 'clarify_intent');
    }

    /** « Parler à quelqu'un » : l'assistant se tait le temps que l'équipe réponde. */
    public function handoff(Turn $turn): void
    {
        $turn->conv->reset();
        $turn->say('menu.handoff', ['business' => $turn->business()]);
        $turn->conv->go(self::HANDOFF, IntentDetector::HANDOFF);
        $turn->conv->handoffUntil = CarbonImmutable::now()->addHours((int) config('conversation.handoff_hours'));
    }

    /** Le client est invité à répondre à un rappel (appelé quand un rappel part, voir ConversationEngine::expectReminderReply). */
    public function reminderState(Turn $turn, string $bookingId): void
    {
        $turn->conv->reset();
        $turn->conv->set('appointment_id', $bookingId);
        $turn->conv->go(self::REMINDER, 'reminder_response');
    }

    public function handle(Turn $turn): ?string
    {
        return match ($turn->conv->state) {
            self::MAIN => $this->onMain($turn),
            self::EXISTING_BOOKING => $this->onExistingBooking($turn),
            self::EXISTING_APPOINTMENT => $this->onExistingAppointment($turn),
            self::CLARIFY => $this->onClarify($turn),
            self::REMINDER => $this->onReminder($turn),
            default => null,
        };
    }

    private function onMain(Turn $turn): ?string
    {
        return match (true) {
            $this->is($turn, '1', 'rdv') || Keywords::contains($turn->message(), 'book') => IntentDetector::BOOKING,
            $this->is($turn, '2', 'question') => self::ASK_FAQ,
            $this->isQuestion($turn) => IntentDetector::FAQ,
            default => null,
        };
    }

    private function onExistingBooking(Turn $turn): string
    {
        $message = $turn->message();

        switch (true) {
            case $this->is($turn, '1', 'confirm_presence'):
                $this->presence($turn);

                return self::HANDLED;
            case $this->is($turn, '2') || Keywords::contains($message, 'modify'):
                return IntentDetector::MODIFY;
            case $this->is($turn, '3') || Keywords::contains($message, 'cancel'):
                return IntentDetector::CANCEL;
            case $this->is($turn, '4') || Keywords::contains($message, 'new_booking'):
                return self::NEW_BOOKING;
            case $this->is($turn, '5', 'question'):
                return self::ASK_FAQ;
            case $this->isQuestion($turn):
                return IntentDetector::FAQ;
            default:
                $this->greeting($turn); // choix incompris : le menu est reproposé

                return self::HANDLED;
        }
    }

    private function onExistingAppointment(Turn $turn): string
    {
        $message = $turn->message();

        switch (true) {
            case $this->is($turn, '1', 'modify') || Keywords::contains($message, 'modify'):
                return IntentDetector::MODIFY;
            case $this->is($turn, '2', 'new_booking'):
                return self::NEW_BOOKING;
            case $this->is($turn, '3', 'cancel'):
                return IntentDetector::CANCEL;
            default:
                $this->existingAppointment($turn);

                return self::HANDLED;
        }
    }

    private function onClarify(Turn $turn): string
    {
        $message = $turn->message();

        switch (true) {
            case $this->is($turn, '1') || Keywords::contains($message, 'book'):
                return IntentDetector::BOOKING;
            case $this->is($turn, '2') || Keywords::contains($message, 'modify'):
                return IntentDetector::MODIFY;
            case $this->is($turn, '3') || Keywords::contains($message, 'cancel'):
                return IntentDetector::CANCEL;
            case $this->is($turn, '4', 'question'):
                return self::ASK_FAQ;
            case $this->isQuestion($turn):
                return IntentDetector::FAQ;
            case $this->is($turn, '5') || Keywords::contains($message, 'human'):
                return IntentDetector::HANDOFF;
            default:
                $this->clarify($turn);

                return self::HANDLED;
        }
    }

    private function onReminder(Turn $turn): string
    {
        $message = $turn->message();

        switch (true) {
            case $this->is($turn, '1', 'confirm_presence', 'yes'):
                $this->presence($turn);

                return self::HANDLED;
            case $this->is($turn, '2', 'cancel') || Keywords::contains($message, 'cancel'):
                return IntentDetector::CANCEL;
            case Keywords::contains($message, 'modify'):
                return IntentDetector::MODIFY;
            case $this->isQuestion($turn):
                return IntentDetector::FAQ;
            default:
                $turn->say('menu.reminder_invalid');

                return self::HANDLED;
        }
    }

    private function isQuestion(Turn $turn): bool
    {
        return Keywords::contains($turn->message(), 'faq') || str_ends_with(trim($turn->text), '?');
    }

    /** Présence confirmée (réponse au rappel ou au menu de salutation). */
    private function presence(Turn $turn): void
    {
        $booking = $this->gateway->booking($turn, $turn->conv->string('appointment_id')) ?? ($this->gateway->upcoming($turn)[0] ?? null);
        $turn->conv->reset();

        if ($booking === null) {
            $turn->say('cancel.none');

            return;
        }

        $turn->say('menu.presence_confirmed', $this->appointmentData($turn, $booking) + ['business' => $turn->business(), 'name' => $turn->name()]);
    }

    /**
     * @return array<string, string>
     */
    private function appointmentData(Turn $turn, Booking $booking): array
    {
        $described = $this->describe($turn, $booking);
        $local = $turn->local($booking->starts_at);

        return [
            'name' => $turn->name(),
            'service' => $described['service'],
            'staff' => $described['staff'],
            'staff_line' => $described['staff'] === '' ? '' : "\n".$turn->t('menu.with_staff', ['staff' => $described['staff']]),
            'date' => $described['date'],
            'short_date' => $turn->shortDate($local),
            'time' => $described['time'],
            'time_h' => str_replace(':', 'h', $described['time']),
        ];
    }
}
