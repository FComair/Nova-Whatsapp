<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Changer le praticien d'un rendez-vous depuis la fenêtre de détail : même heure, mêmes règles qu'un déplacement.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
    $this->colleague = fn (bool $performsService = true, string $status = 'active'): Practitioner => $this->asOwner(function () use ($performsService, $status): Practitioner {
        $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7', 'status' => $status]);
        $performsService && DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
        PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

        return $practitioner;
    });
    $this->book = fn (?Practitioner $practitioner = null, string $key = 'k'): Booking => app(TenantContext::class)->run($this->org->id, fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00', practitioner: $practitioner, key: $key)));
    $this->current = fn (Booking $booking): string => $this->asOwner(fn () => (string) Booking::query()->whereKey($booking->id)->value('practitioner_id'));
});

it('remplace le praticien sans toucher à l\'heure et le consigne dans le journal', function (): void {
    $marc = ($this->colleague)();
    $booking = ($this->book)();
    $start = $booking->starts_at;

    ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => $marc->id])->assertSessionHasNoErrors()->assertSessionHas('status', 'Praticien mis à jour.');

    expect(($this->current)($booking))->toBe($marc->id);
    expect($this->asOwner(fn () => Booking::query()->findOrFail($booking->id)->starts_at->equalTo($start)))->toBeTrue();
    expect($this->asOwner(fn () => BookingEvent::query()->where('booking_id', $booking->id)->where('action', 'moved')->exists()))->toBeTrue();
});

it('ne fait rien quand le praticien est le même', function (): void {
    $booking = ($this->book)();

    ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => $this->practitioner->id])->assertSessionHasNoErrors();

    expect($this->asOwner(fn () => BookingEvent::query()->where('booking_id', $booking->id)->where('action', 'moved')->count()))->toBe(0);
});

it('refuse un praticien qui ne réalise pas le service, ou inactif', function (): void {
    $booking = ($this->book)();

    ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => ($this->colleague)(performsService: false)->id])->assertSessionHasErrors('booking');
    expect(($this->current)($booking))->toBe($this->practitioner->id);
});

it('refuse quand le créneau du nouveau praticien est déjà pris', function (): void {
    $marc = ($this->colleague)();
    ($this->book)($marc, 'marc');
    $booking = ($this->book)(null, 'mine');

    ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => $marc->id])->assertSessionHasErrors('slot');
    expect(($this->current)($booking))->toBe($this->practitioner->id);
});

it('permet au réceptionniste mais pas au praticien', function (): void {
    $marc = ($this->colleague)();
    $booking = ($this->book)();
    $receptionist = ($this->member)('receptionist');
    $lea = ($this->member)('practitioner', $this->practitioner);

    ($this->as)($lea)->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => $marc->id])->assertForbidden();
    ($this->as)($receptionist)->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => $marc->id])->assertSessionHasNoErrors();
    expect(($this->current)($booking))->toBe($marc->id);
});

it('valide l\'identifiant du praticien', function (): void {
    $booking = ($this->book)();

    ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => 'pas-un-uuid'])->assertSessionHasErrors('practitioner_id');
    ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", [])->assertSessionHasErrors('practitioner_id');
});

it('envoie au planning le nom du praticien, le service et le droit de modifier', function (): void {
    $booking = ($this->book)();
    $lea = ($this->member)('practitioner', $this->practitioner);

    ($this->as)()->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page
        ->where('bookings.0.id', $booking->id)
        ->where('bookings.0.practitioner_name', $this->practitioner->name)
        ->where('bookings.0.service_id', $this->service->id)
        ->where('canReassign', true));
    ($this->as)($lea)->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->where('canReassign', false));
});
