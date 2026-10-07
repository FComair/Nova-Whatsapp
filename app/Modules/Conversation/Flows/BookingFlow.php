<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Flows;

use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Catalog\Models\Service;
use App\Modules\Conversation\Services\BookingGateway;
use App\Modules\Conversation\Support\DayParser;
use App\Modules\Conversation\Support\PageView;
use App\Modules\Conversation\Support\Text;
use App\Modules\Conversation\Support\Turn;
use App\Modules\Staff\Models\Practitioner;
use Illuminate\Support\Str;

/**
 * Parcours de réservation, dans l'ordre du flux n8n :
 * service → jour → praticien (sauté s'il n'y en a qu'un) → créneau → nom → e-mail (facultatif) → récapitulatif → confirmation.
 *
 * Le rendez-vous n'est créé qu'à la confirmation, par le cœur de réservation ; paiement sur place uniquement.
 */
final class BookingFlow extends Flow
{
    public const SERVICE = 'waiting_service_choice';

    public const DAY = 'waiting_booking_day';

    public const STAFF = 'waiting_staff_choice';

    public const SLOT = 'waiting_slot_choice';

    public const NAME = 'waiting_customer_name';

    public const EMAIL = 'waiting_customer_email';

    public const CONFIRM = 'waiting_booking_confirmation';

    public const STATES = [self::SERVICE, self::DAY, self::STAFF, self::SLOT, self::NAME, self::EMAIL, self::CONFIRM];

    public function __construct(BookingGateway $gateway, private readonly ModifyFlow $modify)
    {
        parent::__construct($gateway);
    }

    public function handle(Turn $turn): void
    {
        match ($turn->conv->state) {
            self::SERVICE => $this->onService($turn),
            self::DAY => $this->onDay($turn),
            self::STAFF => $this->onStaff($turn),
            self::SLOT => $this->onSlot($turn),
            self::NAME => $this->onName($turn),
            self::EMAIL => $this->onEmail($turn),
            self::CONFIRM => $this->onConfirm($turn),
            default => $turn->conv->reset(),
        };
    }

    /** Le client a-t-il déjà un rendez-vous à venir ? */
    public function hasUpcoming(Turn $turn): bool
    {
        return $this->gateway->upcoming($turn) !== [];
    }

    /** Début d'une nouvelle réservation : le menu des services. */
    public function start(Turn $turn): void
    {
        $turn->conv->reset();
        $turn->conv->set('attempt', (string) Str::uuid());
        $turn->conv->set('mode', self::DRAFT);

        $services = $this->gateway->services();

        if ($services === []) {
            $turn->say('booking.no_service');

            return;
        }

        $turn->conv->set('list', array_map(static fn (Service $service): string => $service->id, $services));
        $this->showServices($turn, 0);
    }

    private function showServices(Turn $turn, int $page): void
    {
        $view = PageView::of($turn->conv->list('list'), $page, $this->size());
        $labels = array_map(fn (string $id): string => $this->serviceName($turn, $id), $view->items);

        $turn->conv->set('page', $view->page);
        $turn->say($view->page === 0 ? 'booking.service_menu' : 'booking.service_page', [
            'lines' => $this->lines($view, $labels, $turn->t('common.more_services'), $turn->t('common.previous_page')),
        ]);
        $turn->conv->go(self::SERVICE, 'booking');
    }

    private function serviceName(Turn $turn, string $id): string
    {
        $service = $this->gateway->service($id);

        return $service === null ? '' : $this->serviceLabel($turn, $service);
    }

    private function onService(Turn $turn): void
    {
        $view = PageView::of($turn->conv->list('list'), (int) $turn->conv->get('page', 0), $this->size());
        [$type, $index] = $view->resolve($turn->text);

        if ($type === 'more' || $type === 'previous') {
            $this->showServices($turn, $view->page + ($type === 'more' ? 1 : -1));

            return;
        }

        $service = $type === 'select' ? $this->gateway->service($view->items[$index]) : null;

        if ($service === null) {
            $turn->say('booking.invalid_service');

            return;
        }

        $turn->conv->set('service_id', $service->id);
        $turn->conv->forget('practitioner_id', 'no_preference', 'date', 'time', 'times');
        $turn->say('booking.day_prompt', [
            'service' => $service->name,
            'duration' => $service->duration_minutes,
            'price' => $turn->price($service->price_cents),
        ]);
        $turn->conv->go(self::DAY);
    }

    private function onDay(Turn $turn): void
    {
        $date = DayParser::parse($turn->text, $turn->today());

        if ($date === null) {
            $turn->say('common.invalid_day');

            return;
        }

        $service = $this->chosenService($turn);

        if ($service === null) {
            $turn->conv->reset();

            return;
        }

        $day = $date->toDateString();
        $turn->conv->set('date', $day);
        $turn->conv->forget('practitioner_id', 'no_preference', 'time', 'times');
        $available = $this->gateway->practitionersWithTimes($turn, $service, $day);

        if ($available === []) {
            $turn->say('booking.no_slot_day', $this->recap($turn, $service));
            $turn->conv->go(self::DAY);

            return;
        }

        // Un seul praticien : il est choisi d'office (le menu des praticiens est sauté).
        if (count($available) === 1) {
            $turn->conv->set('practitioner_id', $available[0]->id);
            $this->showTimes($turn, 0, true);

            return;
        }

        $turn->conv->set('list', array_map(static fn (Practitioner $practitioner): string => $practitioner->id, $available));
        $this->showStaff($turn, 0);
    }

    /**
     * @return array<string, string|int>
     */
    private function recap(Turn $turn, Service $service): array
    {
        $practitioner = $this->chosenPractitioner($turn);

        return [
            'service' => $service->name,
            'duration' => $this->gateway->duration($service, $practitioner),
            'price' => $turn->price($this->gateway->price($service, $practitioner)),
            'date' => $this->dateLabel($turn),
            'staff' => $this->staffLabel($turn),
        ];
    }

    private function staffMenu(Turn $turn, PageView $view): string
    {
        $labels = array_map(fn (string $id): string => $this->gateway->practitioner($id)->name ?? '', $view->items);

        return $this->lines($view, $labels, $turn->t('common.more_staff'), $turn->t('common.previous_page'), [$turn->t('common.no_preference')]);
    }

    private function showStaff(Turn $turn, int $page): void
    {
        $view = PageView::of($turn->conv->list('list'), $page, $this->size());
        $service = $this->chosenService($turn);

        $turn->conv->set('page', $view->page);
        $turn->say('booking.staff_menu', [...($service === null ? [] : $this->recap($turn, $service)), 'menu' => $this->staffMenu($turn, $view)]);
        $turn->conv->go(self::STAFF);
    }

    private function onStaff(Turn $turn): void
    {
        $view = PageView::of($turn->conv->list('list'), (int) $turn->conv->get('page', 0), $this->size());
        [$type, $index] = $view->resolve($turn->text, 1);

        match ($type) {
            'more' => $this->showStaff($turn, $view->page + 1),
            'previous' => $this->showStaff($turn, $view->page - 1),
            'select' => $this->pickStaff($turn, $view->items[$index]),
            'extra' => $this->pickStaff($turn, null),
            default => $turn->say('booking.invalid_staff', ['menu' => $this->staffMenu($turn, $view)]),
        };
    }

    private function pickStaff(Turn $turn, ?string $practitionerId): void
    {
        if ($practitionerId === null) {
            $turn->conv->forget('practitioner_id');
            $turn->conv->set('no_preference', true);
        } else {
            $turn->conv->set('practitioner_id', $practitionerId);
            $turn->conv->forget('no_preference');
        }

        $this->showTimes($turn, 0, true);
    }

    private function showTimes(Turn $turn, int $page, bool $reload): void
    {
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');

        if ($service === null || $date === null) {
            $turn->conv->reset();

            return;
        }

        $times = $reload ? $this->gateway->times($turn, $service, $this->chosenPractitioner($turn), $date) : $turn->conv->list('times');

        if ($times === []) {
            $turn->say('booking.no_slot_staff', $this->recap($turn, $service));
            $turn->conv->go(self::DAY);

            return;
        }

        $view = PageView::of($times, $page, $this->size());
        $turn->conv->set('times', $times);
        $turn->conv->set('time_page', $view->page);
        $turn->say($reload ? 'booking.time_menu' : 'booking.time_page', [...$this->recap($turn, $service), 'slots' => $this->timeLines($turn, $times, $view->page)]);
        $turn->conv->go(self::SLOT);
    }

    private function onSlot(Turn $turn): void
    {
        $page = (int) $turn->conv->get('time_page', 0);
        [$type, $value] = $this->resolveTime($turn, $turn->conv->list('times'), $page);

        if ($type === 'more' || $type === 'previous') {
            $this->showTimes($turn, $page + ($type === 'more' ? 1 : -1), false);

            return;
        }

        $service = $this->chosenService($turn);

        if ($type !== 'select' || $service === null) {
            $turn->say('common.invalid_choice');

            return;
        }

        $turn->conv->set('time', (string) $value);
        $turn->say('booking.name_prompt', [...$this->recap($turn, $service), 'time' => (string) $value]);
        $turn->conv->go(self::NAME);
    }

    private function onName(Turn $turn): void
    {
        $name = trim($turn->text);

        if (! Text::isPersonName($name)) {
            $turn->say('booking.invalid_name');

            return;
        }

        $name = Str::limit($name, 120, '');
        $turn->conv->set('name', $name);
        $turn->say('booking.email_prompt', ['name' => $name]);
        $turn->conv->go(self::EMAIL);
    }

    private function onEmail(Turn $turn): void
    {
        if ($this->is($turn, '0', 'skip_email')) {
            $turn->conv->set('email_skipped', true);
            $turn->conv->forget('email');
        } elseif (Text::isEmail($turn->text)) {
            $turn->conv->set('email', trim($turn->text));
            $turn->conv->forget('email_skipped');
        } else {
            $turn->say('booking.invalid_email');

            return;
        }

        $this->summary($turn);
    }

    private function onConfirm(Turn $turn): void
    {
        match (true) {
            $this->is($turn, '1', 'confirm') => $this->confirm($turn),
            $this->is($turn, '2', 'modify_again', 'modify', 'no') => $this->modify->startDraft($turn),
            $this->is($turn, '3', 'cancel', 'abandon') => $this->abandon($turn),
            default => $turn->say('common.invalid_choice'),
        };
    }

    private function abandon(Turn $turn): void
    {
        $turn->say('booking.abandoned');
        $turn->conv->reset();
    }

    private function confirm(Turn $turn): void
    {
        $service = $this->chosenService($turn);
        $date = $turn->conv->string('date');
        $time = $turn->conv->string('time');

        if ($service === null || $date === null || $time === null) {
            $turn->say('common.session_expired');
            $turn->conv->reset();

            return;
        }

        // « Aucune préférence » : le premier praticien libre à ce moment est choisi au dernier instant.
        [$practitioner] = $this->gateway->practitionerAt($turn, $service, $this->chosenPractitioner($turn), $date, $time);

        if ($practitioner === null) {
            $this->slotTaken($turn, self::SLOT, self::DAY);

            return;
        }

        $name = $turn->conv->string('name') ?? $turn->name();

        try {
            $booking = $this->gateway->book(
                $turn, $service, $practitioner, $date, $time,
                $name === '' ? null : __('conversation.booking_note', ['name' => $name], $turn->org->locale),
                (string) $turn->conv->get('attempt', $turn->customer->id),
            );
        } catch (SlotUnavailable) {
            $this->slotTaken($turn, self::SLOT, self::DAY);

            return;
        } catch (BookingRuleViolation) {
            $turn->say('booking.refused', ['business' => $turn->business()]);
            $turn->conv->reset();

            return;
        }

        $this->rememberCustomer($turn);
        $described = $this->describe($turn, $booking);
        $turn->say('booking.confirmed', [
            'name' => $name,
            'date' => $described['date'],
            'start' => $described['time'],
            'end' => $described['end'],
            'service' => $described['service'],
            'duration' => $described['duration'],
            'staff' => $described['staff'],
            'price' => $turn->price($described['price']),
            'business' => $turn->business(),
            'address' => $turn->address(),
        ]);
        $turn->conv->reset();
    }

    /** Complète la fiche du client avec ce qu'il vient de donner, sans jamais écraser ce que l'établissement a saisi. */
    private function rememberCustomer(Turn $turn): void
    {
        $updates = [];
        $name = $turn->conv->string('name');
        $email = $turn->conv->string('email');

        if ($name !== null && $turn->name() === '') {
            $updates['name'] = $name;
        }

        if ($email !== null && trim((string) $turn->customer->email) === '') {
            $updates['email'] = $email;
        }

        if ($updates !== []) {
            $turn->customer->forceFill($updates)->save();
        }
    }
}
