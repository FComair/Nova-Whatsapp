<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Flows;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Catalog\Models\Service;
use App\Modules\Conversation\Support\DayParser;
use App\Modules\Conversation\Support\PageView;
use App\Modules\Conversation\Support\Text;
use App\Modules\Conversation\Support\Turn;
use App\Modules\Staff\Models\Practitioner;
use Illuminate\Support\Str;

/**
 * Modification, dans le même menu pour un rendez-vous existant et pour une réservation en cours de saisie :
 * 1 service, 2 praticien, 3 jour, 4 heure, 5 conserver / retour.
 *
 * Le moteur « prépare » (récapitulatif et confirmation) ; le cœur de réservation « applique » à la confirmation.
 * Changer le service annule l'ancien rendez-vous et en crée un nouveau en une seule opération (tout ou rien).
 */
final class ModifyFlow extends Flow
{
    public const CHOOSE = 'waiting_modify_appointment_choice';

    public const MENU = 'waiting_modify_choice';

    public const SERVICE = 'waiting_new_service_choice';

    public const STAFF = 'waiting_new_staff_choice';

    public const NO_STAFF = 'waiting_new_staff_none';

    public const DAY = 'waiting_new_day_choice';

    public const SLOT = 'waiting_new_slot_choice';

    public const NEXT_DAYS = 'waiting_new_next_days_choice';

    public const CONFIRM = 'waiting_modification_confirmation';

    public const STATES = [self::CHOOSE, self::MENU, self::SERVICE, self::STAFF, self::NO_STAFF, self::DAY, self::SLOT, self::NEXT_DAYS, self::CONFIRM];

    public function handle(Turn $turn): void
    {
        match ($turn->conv->state) {
            self::CHOOSE => $this->onChoose($turn),
            self::MENU => $this->onMenu($turn),
            self::SERVICE => $this->onService($turn),
            self::STAFF => $this->onStaff($turn),
            self::NO_STAFF => $this->onNoStaff($turn),
            self::DAY => $this->onDay($turn),
            self::SLOT => $this->onSlot($turn),
            self::NEXT_DAYS => $this->onNextDays($turn),
            self::CONFIRM => $this->onConfirm($turn),
            default => $turn->conv->reset(),
        };
    }

    /** Le client veut modifier un rendez-vous existant. */
    public function start(Turn $turn): void
    {
        $bookings = $this->gateway->upcoming($turn);

        if ($bookings === []) {
            $turn->say('modify.none');
            $turn->conv->reset();

            return;
        }

        if (count($bookings) === 1) {
            $this->open($turn, $bookings[0], 'modify.menu_single');

            return;
        }

        $lines = [];

        foreach ($bookings as $index => $booking) {
            $described = $this->describe($turn, $booking);
            $lines[] = $turn->t('modify.choose_line', [
                'number' => Text::digit($index + 1), 'name' => $turn->name() === '' ? $turn->t('common.appointment') : $turn->name(),
                'service' => $described['service'], 'staff' => $described['staff'], 'date' => $described['date'], 'time' => $described['time'],
            ]);
        }

        $turn->conv->reset();
        $turn->conv->set('ids', array_map(static fn (Booking $booking): string => $booking->id, $bookings));
        $turn->say('modify.choose', ['lines' => implode("\n\n", $lines)]);
        $turn->conv->go(self::CHOOSE, 'modify_booking');
    }

    /** Depuis le récapitulatif d'une réservation en cours de saisie (« 2 Modifier »). */
    public function startDraft(Turn $turn): void
    {
        $turn->conv->set('mode', self::DRAFT);
        $this->showMenu($turn, 'modify.menu_draft');
    }

    private function exists(Turn $turn): bool
    {
        return $turn->conv->get('mode') === self::EXISTING;
    }

    private function open(Turn $turn, Booking $booking, string $menu): void
    {
        if (! $this->gateway->canChange($turn, $booking)) {
            $turn->say('modify.too_late', ['business' => $turn->business()]);
            $turn->conv->reset();

            return;
        }

        $local = $turn->local($booking->starts_at);
        $turn->conv->reset();
        $turn->conv->set('mode', self::EXISTING);
        $turn->conv->set('appointment_id', $booking->id);
        $turn->conv->set('service_id', $booking->service_id);
        $turn->conv->set('practitioner_id', $booking->practitioner_id);
        $turn->conv->set('date', $local->toDateString());
        $turn->conv->set('time', $local->format('H:i'));
        $turn->conv->set('attempt', (string) Str::uuid());
        $this->showMenu($turn, $menu);
    }

    private function showMenu(Turn $turn, string $key): void
    {
        $turn->say($key, $this->data($turn));
        $turn->conv->go(self::MENU, 'modify_booking');
    }

    /**
     * Les éléments du rendez-vous (ou de la réservation) en cours, pour les messages.
     *
     * @return array<string, string|int>
     */
    private function data(Turn $turn): array
    {
        $service = $this->chosenService($turn);
        $practitioner = $this->chosenPractitioner($turn);
        $time = $turn->conv->string('time');

        return [
            'name' => $turn->name(),
            'spaced' => $turn->spacedName(),
            'service' => $service->name ?? '',
            'duration' => $service === null ? '-' : $this->gateway->duration($service, $practitioner),
            'price' => $service === null ? '-' : $turn->price($this->gateway->price($service, $practitioner)),
            'staff' => $this->staffLabel($turn),
            'date' => $turn->conv->string('date') === null ? '' : Str::ucfirst($this->dateLabel($turn)),
            'time' => $time ?? '',
            'details' => $this->details($turn),
            'selection' => $this->selection($turn),
        ];
    }

    private function details(Turn $turn): string
    {
        $service = $this->chosenService($turn);
        $lines = [];

        $service !== null && $lines[] = $turn->t('modify.detail_service', ['value' => $service->name]);
        $lines[] = $turn->t('modify.detail_staff', ['value' => $this->staffLabel($turn)]);
        $turn->conv->string('date') !== null && $lines[] = $turn->t('modify.detail_date', ['value' => $this->dateLabel($turn)]);
        $turn->conv->string('time') !== null && $lines[] = $turn->t('modify.detail_time', ['value' => (string) $turn->conv->string('time')]);

        return implode("\n", $lines);
    }

    /** Bloc « sélection actuelle » (retour à la ligne initial inclus), vide s'il n'y a rien à montrer. */
    private function selection(Turn $turn): string
    {
        $service = $this->chosenService($turn);
        $lines = [];

        $service !== null && $lines[] = '💈 '.$service->name;
        $lines[] = '👤 '.$this->staffLabel($turn);
        $turn->conv->string('date') !== null && $lines[] = '📅 '.$this->dateLabel($turn);
        $turn->conv->string('time') !== null && $lines[] = '🕐 '.$turn->conv->string('time');

        return "\n\n".implode("\n", $lines);
    }

    private function onChoose(Turn $turn): void
    {
        $ids = $turn->conv->list('ids');
        $number = Text::number($turn->text);
        $booking = $number !== null && $number >= 1 && $number <= count($ids) ? $this->gateway->booking($turn, $ids[$number - 1]) : null;

        if ($booking === null) {
            $turn->say('modify.invalid_appointment');

            return;
        }

        $this->open($turn, $booking, 'modify.menu_chosen');
    }

    private function onMenu(Turn $turn): void
    {
        match (true) {
            $this->is($turn, '1', 'pick_service') => $this->showServices($turn, 0, true),
            $this->is($turn, '2', 'pick_staff') => $this->showStaff($turn, 0, true),
            $this->is($turn, '3', 'pick_day') => $this->dayPrompt($turn),
            $this->is($turn, '4', 'pick_time') => $this->timesPrompt($turn),
            $this->is($turn, '5', 'pick_back') => $this->back($turn),
            default => $this->invalidMenu($turn),
        };
    }

    private function invalidMenu(Turn $turn): void
    {
        $turn->say('common.invalid_choice');
        $this->showMenu($turn, $this->exists($turn) ? 'modify.menu_again' : 'modify.menu_draft');
    }

    /** « 5 » : on garde le rendez-vous tel qu'il est, ou on revient au récapitulatif de la réservation. */
    private function back(Turn $turn): void
    {
        if (! $this->exists($turn)) {
            $this->summary($turn);

            return;
        }

        $booking = $this->gateway->booking($turn, $turn->conv->string('appointment_id'));

        if ($booking !== null) {
            $this->keep($turn, $booking);
        }

        $turn->conv->reset();
    }

    private function keep(Turn $turn, Booking $booking): void
    {
        $described = $this->describe($turn, $booking);
        $turn->say('modify.kept', [
            'date' => $described['date'], 'time' => $described['time'], 'service' => $described['service'],
            'staff' => $described['staff'], 'business' => $turn->business(),
        ]);
    }

    /* --- Service --- */

    private function showServices(Turn $turn, int $page, bool $reload): void
    {
        if ($reload) {
            $services = $this->gateway->services();

            if ($services === []) {
                $turn->say('modify.no_service');
                $this->showMenu($turn, $this->exists($turn) ? 'modify.menu_again' : 'modify.menu_draft');

                return;
            }

            $turn->conv->set('list', array_map(static fn (Service $service): string => $service->id, $services));
        }

        $view = PageView::of($turn->conv->list('list'), $page, $this->size());
        $turn->conv->set('page', $view->page);
        $turn->say($view->page === 0 ? 'modify.service_menu' : 'modify.service_page', ['who' => $turn->who(), 'lines' => $this->serviceMenu($turn, $view)]);
        $turn->conv->go(self::SERVICE);
    }

    private function serviceMenu(Turn $turn, PageView $view): string
    {
        $labels = array_map(function (string $id) use ($turn): string {
            $service = $this->gateway->service($id);

            return $service === null ? '' : $this->serviceLabel($turn, $service);
        }, $view->items);

        return $this->lines($view, $labels, $turn->t('common.more'), $turn->t('common.previous_page'));
    }

    private function onService(Turn $turn): void
    {
        $view = PageView::of($turn->conv->list('list'), (int) $turn->conv->get('page', 0), $this->size());
        [$type, $index] = $view->resolve($turn->text);

        if ($type === 'more' || $type === 'previous') {
            $this->showServices($turn, $view->page + ($type === 'more' ? 1 : -1), false);

            return;
        }

        $service = $type === 'select' ? $this->gateway->service($view->items[$index]) : null;

        if ($service === null) {
            $turn->say('common.invalid_choice_menu', ['menu' => $this->serviceMenu($turn, $view)]);

            return;
        }

        $turn->conv->set('service_id', $service->id);
        $this->afterChange($turn, 'service');
    }

    /* --- Praticien --- */

    private function showStaff(Turn $turn, int $page, bool $reload): void
    {
        $service = $this->chosenService($turn);

        if ($service === null) {
            $turn->conv->reset();

            return;
        }

        $current = $this->chosenPractitioner($turn);
        $currentLine = $current === null ? '' : $turn->t('modify.current_staff', ['staff' => $current->name]);

        if ($reload) {
            $others = array_values(array_filter($this->gateway->practitionersFor($turn, $service), static fn (Practitioner $practitioner): bool => $practitioner->id !== $current?->id));

            if ($others === []) {
                $turn->say('modify.no_other_staff', ['who' => $turn->who(), 'current' => $currentLine]);
                $turn->conv->go(self::NO_STAFF, 'modify_booking');

                return;
            }

            $turn->conv->set('list', array_map(static fn (Practitioner $practitioner): string => $practitioner->id, $others));
        }

        $view = PageView::of($turn->conv->list('list'), $page, $this->size());
        $turn->conv->set('page', $view->page);
        $turn->say('modify.staff_menu', ['who' => $turn->who(), 'current' => $currentLine, 'menu' => $this->staffMenu($turn, $view)]);
        $turn->conv->go(self::STAFF);
    }

    private function staffMenu(Turn $turn, PageView $view): string
    {
        $labels = array_map(fn (string $id): string => $this->gateway->practitioner($id)->name ?? '', $view->items);

        return $this->lines($view, $labels, $turn->t('common.more'), $turn->t('common.previous_page'));
    }

    private function onStaff(Turn $turn): void
    {
        $view = PageView::of($turn->conv->list('list'), (int) $turn->conv->get('page', 0), $this->size());
        [$type, $index] = $view->resolve($turn->text);

        if ($type === 'more' || $type === 'previous') {
            $this->showStaff($turn, $view->page + ($type === 'more' ? 1 : -1), false);

            return;
        }

        if ($type !== 'select') {
            $turn->say('common.invalid_choice_menu', ['menu' => $this->staffMenu($turn, $view)]);

            return;
        }

        $turn->conv->set('practitioner_id', $view->items[$index]);
        $this->afterChange($turn, 'staff');
    }

    private function onNoStaff(Turn $turn): void
    {
        match (true) {
            $this->is($turn, '1', 'pick_menu') => $this->showMenu($turn, $this->exists($turn) ? 'modify.menu_again' : 'modify.menu_draft'),
            $this->is($turn, '2', 'keep') => $this->back($turn),
            default => $turn->say('common.invalid_choice'),
        };
    }

    /* --- Jour --- */

    private function dayPrompt(Turn $turn): void
    {
        $turn->say('modify.day_prompt', $this->data($turn));
        $turn->conv->go(self::DAY);
    }

    private function onDay(Turn $turn): void
    {
        $date = DayParser::parse($turn->text, $turn->today());

        if ($date === null) {
            $turn->say('common.invalid_day');

            return;
        }

        $turn->conv->set('date', $date->toDateString());
        $this->afterChange($turn, 'date');
    }

    /* --- Heure --- */

    private function timesPrompt(Turn $turn): void
    {
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');

        if ($service === null || $date === null) {
            $turn->conv->reset();

            return;
        }

        $times = $this->gateway->times($turn, $service, $this->chosenPractitioner($turn), $date, $this->ignoreId($turn));

        if ($times === []) {
            $turn->say('modify.no_slot_day', ['date' => $this->dateLabel($turn), 'service' => $service->name]);
            $turn->conv->go(self::DAY);

            return;
        }

        $turn->conv->set('times', $times);
        $turn->conv->set('time_page', 0);
        $turn->say('modify.choose_time', ['date' => $this->dateLabel($turn), 'slots' => $this->timeLines($turn, $times, 0), 'example' => $times[0]]);
        $turn->conv->go(self::SLOT);
    }

    private function onSlot(Turn $turn): void
    {
        $times = $turn->conv->list('times');
        $page = (int) $turn->conv->get('time_page', 0);
        [$type, $value] = $this->resolveTime($turn, $times, $page);
        $example = $times[0] ?? '09:00';

        if ($type === 'more' || $type === 'previous') {
            $page = max(0, $page + ($type === 'more' ? 1 : -1));
            $turn->conv->set('time_page', $page);
            $turn->say('modify.slots_page', ['slots' => $this->timeLines($turn, $times, $page), 'example' => $example]);

            return;
        }

        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');

        if ($type !== 'select' || $service === null || $date === null) {
            $turn->say('modify.invalid_slot', ['name' => $turn->spacedName(), 'slots' => $this->timeLines($turn, $times, $page), 'example' => $example]);

            return;
        }

        [$practitioner] = $this->gateway->practitionerAt($turn, $service, $this->chosenPractitioner($turn), $date, (string) $value, $this->ignoreId($turn));

        if ($practitioner === null) {
            $turn->conv->set('time', (string) $value);
            $this->slotTaken($turn, self::SLOT, self::DAY, $this->ignoreId($turn));

            return;
        }

        $turn->conv->set('time', (string) $value);
        $this->exists($turn) && $turn->conv->set('practitioner_id', $practitioner->id);
        $this->ready($turn, 'time');
    }

    /* --- Après un changement : le créneau tient-il toujours ? --- */

    private function ignoreId(Turn $turn): ?string
    {
        return $this->exists($turn) ? $turn->conv->string('appointment_id') : null;
    }

    private function afterChange(Turn $turn, string $cause): void
    {
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');
        $time = $turn->conv->string('time');

        if ($service === null || $date === null || $time === null) {
            $turn->conv->reset();

            return;
        }

        $ignore = $this->ignoreId($turn);
        [$practitioner, $reason] = $this->gateway->practitionerAt($turn, $service, $this->chosenPractitioner($turn), $date, $time, $ignore);

        // Le nouveau service n'est pas réalisé par le praticien actuel : on cherche un autre praticien libre à ce moment.
        if ($reason === 'cannot_perform') {
            $turn->conv->forget('practitioner_id');
            [$practitioner, $reason] = $this->gateway->practitionerAt($turn, $service, null, $date, $time, $ignore);
        }

        if ($practitioner !== null) {
            $this->exists($turn) && $turn->conv->set('practitioner_id', $practitioner->id);
            $this->ready($turn, $cause);

            return;
        }

        $this->recover($turn, $cause);
    }

    /** Le créneau actuel ne tient plus : horaires restants ce jour-là, sinon prochains jours. */
    private function recover(Turn $turn, string $cause): void
    {
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');

        if ($service === null || $date === null) {
            $turn->conv->reset();

            return;
        }

        $times = $this->gateway->times($turn, $service, $this->chosenPractitioner($turn), $date, $this->ignoreId($turn));

        if ($times === []) {
            $this->noDay($turn, $cause);

            return;
        }

        if ($cause === 'staff') {
            $turn->conv->set('times', $times);
            $turn->conv->set('time_page', 0);
            $turn->say('modify.staff_busy', [
                'staff' => $this->staffLabel($turn), 'time' => $turn->conv->string('time') ?? '', 'date' => $this->dateLabel($turn),
                'slots' => $this->timeLines($turn, $times, 0),
            ]);
            $turn->conv->go(self::SLOT);

            return;
        }

        $this->slotTaken($turn, self::SLOT, self::DAY, $this->ignoreId($turn));
    }

    private function noDay(Turn $turn, string $cause): void
    {
        if ($cause === 'staff') {
            $turn->say('modify.no_availability_staff', ['name' => $turn->spacedName(), 'staff' => $this->staffLabel($turn), 'date' => $this->dateLabel($turn)]);
            $turn->conv->go(self::NEXT_DAYS, 'modify_booking');

            return;
        }

        $this->suggestDays($turn, 'modify.reason_closed');
    }

    private function suggestDays(Turn $turn, string $reasonKey): void
    {
        $service = $this->chosenService($turn);

        if ($service === null) {
            $turn->conv->reset();

            return;
        }

        $practitioner = $this->chosenPractitioner($turn);
        $days = $this->gateway->nextDays($turn, $service, $practitioner, 5, $this->ignoreId($turn));

        if ($days === []) {
            $turn->say('modify.nothing_found', ['business' => $turn->business()]);
            $this->showMenu($turn, $this->exists($turn) ? 'modify.menu_again' : 'modify.menu_draft');

            return;
        }

        $who = $practitioner->name ?? $turn->business();
        $turn->say('modify.staff_unavailable', [
            'name' => $turn->spacedName(),
            'reason' => $turn->t($reasonKey, ['staff' => $who, 'date' => $this->dateLabel($turn)]),
            'business' => $turn->business(),
            'staff' => $who,
            'suggestions' => implode("\n", array_map(static fn (string $day): string => '📅 '.$turn->longDateOf($day), $days)),
        ]);
        $turn->conv->go(self::DAY, 'modify_booking');
    }

    private function onNextDays(Turn $turn): void
    {
        match (true) {
            $this->is($turn, '1', 'yes') => $this->suggestDays($turn, 'modify.reason_none_left'),
            $this->is($turn, '2', 'no') => $this->showMenu($turn, $this->exists($turn) ? 'modify.menu_again' : 'modify.menu_draft'),
            default => $turn->say('common.invalid_choice'),
        };
    }

    /** Le rendez-vous modifié est possible : récapitulatif à confirmer (ou retour au récapitulatif de la réservation). */
    private function ready(Turn $turn, string $cause): void
    {
        if (! $this->exists($turn)) {
            $this->summary($turn);

            return;
        }

        $key = match ($cause) {
            'staff' => 'modify.staff_done',
            'date' => 'modify.keep_time',
            'time' => 'modify.time_done',
            default => 'modify.prepared',
        };

        $turn->say($key, $this->data($turn));
        $turn->conv->go(self::CONFIRM, 'modify_booking');
    }

    /* --- Confirmation --- */

    private function onConfirm(Turn $turn): void
    {
        match (true) {
            $this->is($turn, '1', 'confirm') => $this->apply($turn),
            $this->is($turn, '2', 'modify_again', 'modify') => $this->showMenu($turn, 'modify.menu_again'),
            $this->is($turn, '3', 'abandon') => $this->back($turn),
            default => $turn->say('common.invalid_choice'),
        };
    }

    private function apply(Turn $turn): void
    {
        $booking = $this->gateway->booking($turn, $turn->conv->string('appointment_id'));
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');
        $time = $turn->conv->string('time');

        if ($booking === null || $service === null || $date === null || $time === null) {
            $turn->say('modify.none');
            $turn->conv->reset();

            return;
        }

        [$practitioner] = $this->gateway->practitionerAt($turn, $service, $this->chosenPractitioner($turn), $date, $time, $booking->id);

        if ($practitioner === null) {
            $this->recover($turn, 'time');

            return;
        }

        try {
            $updated = $service->id !== $booking->service_id
                ? $this->gateway->changeService($turn, $booking, $service, $practitioner, $date, $time, (string) $turn->conv->get('attempt', $booking->id))
                : $this->gateway->move($turn, $booking, $practitioner, $date, $time);
        } catch (SlotUnavailable) {
            $this->recover($turn, 'time');

            return;
        } catch (BookingRuleViolation $violation) {
            $turn->say($violation->rule === BookingRuleViolation::CANCELLATION_TOO_LATE ? 'modify.too_late' : 'modify.refused', ['business' => $turn->business()]);
            $turn->conv->reset();

            return;
        }

        $described = $this->describe($turn, $updated);
        $turn->say('modify.done', [
            'name' => $turn->name(), 'service' => $described['service'], 'price' => $turn->price($described['price']),
            'staff' => $described['staff'], 'date' => Str::ucfirst($described['date']), 'time' => $described['time'],
        ]);
        $turn->conv->reset();
    }
}
