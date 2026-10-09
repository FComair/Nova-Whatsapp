<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Http\Controllers;

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyReward;
use App\Modules\Loyalty\Services\LoyaltyEngine;
use App\Modules\Loyalty\Services\LoyaltyRedemptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Actions du personnel sur un client ou une récompense (docs/27) : ajuster des tampons, inscrire, retirer, utiliser une récompense, annuler
 * une utilisation. Les droits sont posés par les routes ; chaque action qui corrige quelque chose exige un motif.
 */
final class LoyaltyMemberController
{
    public function __construct(private readonly LoyaltyEngine $engine, private readonly LoyaltyRedemptions $redemptions) {}

    public function adjust(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'not_in:0', 'between:-50,50'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.*' => __('ui.loyalty.errors.reason_required'), 'delta.*' => __('ui.loyalty.errors.invalid_amount')]);

        return $this->attempt(fn () => $this->engine->adjust($customer, (int) $data['delta'], $data['reason'], (string) $request->user()?->id), 'ui.loyalty.customer.adjusted');
    }

    public function enroll(Request $request, Customer $customer): RedirectResponse
    {
        return $this->attempt(fn () => $this->engine->enroll($customer, (string) $request->user()?->id), 'ui.loyalty.customer.enrolled');
    }

    public function optOut(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->engine->optOut($customer, (string) $request->user()?->id, $data['reason'] ?? null), 'ui.loyalty.customer.opted_out');
    }

    public function rejoin(Request $request, Customer $customer): RedirectResponse
    {
        return $this->attempt(fn () => $this->engine->rejoin($customer, (string) $request->user()?->id), 'ui.loyalty.customer.rejoined');
    }

    /** Utilise la récompense sur un rendez-vous du client : le prix du rendez-vous et l'encaissé ne changent pas. */
    public function redeem(Request $request, LoyaltyReward $reward): RedirectResponse
    {
        $data = $request->validate(['booking_id' => ['nullable', 'uuid'], 'other_offer' => ['nullable', 'boolean']]);
        $booking = ($data['booking_id'] ?? null) === null ? null : Booking::query()->findOrFail($data['booking_id']);

        try {
            $used = $this->redemptions->redeem($reward, $booking, (string) $request->user()?->id, (bool) ($data['other_offer'] ?? false));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reward' => __("ui.loyalty.errors.redeem.{$e->getMessage()}")]);
        }

        return back()->with('loyalty_redeemed', [
            'reward_id' => $used->id,
            'value_cents' => (int) $used->applied_value_cents,
            'price_cents' => $booking?->price_cents,
            'booking_id' => $booking?->id,
            'description' => $used->description(app()->getLocale()),
        ])->with('status', __('ui.loyalty.redeem.done_title'));
    }

    public function reverse(Request $request, LoyaltyReward $reward): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.*' => __('ui.loyalty.errors.reason_required')]);

        try {
            $this->redemptions->reverse($reward, (string) $request->user()?->id, $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reward' => __("ui.loyalty.errors.redeem.{$e->getMessage()}")]);
        }

        return back()->with('status', __('ui.loyalty.customer.reversed'));
    }

    /** @param  callable(): mixed  $action */
    private function attempt(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['loyalty' => __("ui.loyalty.errors.{$e->getMessage()}")]);
        }

        return back()->with('status', __($success));
    }
}
