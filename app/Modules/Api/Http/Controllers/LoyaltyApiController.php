<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fidélité d'un client pour le bot (« où en suis-je ? »), en lecture seule. Ne renvoie que ce qui concerne le client dont le numéro est donné,
 * dans sa langue : nom du programme, progression, visites restantes, récompense, expiration et conditions. Un client qui a quitté le
 * programme, un programme brouillon ou sans client connu : `enrolled: false`, sans autre détail.
 */
final class LoyaltyApiController
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['customer_phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/']]);
        $customer = Customer::query()->where('phone_e164', $data['customer_phone'])->whereNull('anonymized_at')->first();
        $program = LoyaltyProgram::current();

        if ($customer === null || $program === null || $program->status === LoyaltyProgram::DRAFT) {
            return new JsonResponse(['data' => ['enrolled' => false]]);
        }

        $member = LoyaltyMember::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->first();

        if ($member === null || ! $member->isActive()) {
            return new JsonResponse(['data' => ['enrolled' => false]]);
        }

        $locale = in_array($customer->language, (array) config('nova.locales'), true) ? (string) $customer->language : (string) config('app.fallback_locale');
        $rewards = LoyaltyReward::query()->where('customer_id', $customer->id)->where('status', LoyaltyReward::AVAILABLE)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderBy('earned_at')->get();

        return new JsonResponse(['data' => [
            'enrolled' => true,
            'program' => $program->text('name', $locale),
            'earning' => $program->isEarning(),
            'stamps' => $member->stamps,
            'required' => $program->required_visits,
            'remaining' => max(0, $program->required_visits - $member->stamps),
            'reward' => $program->text('reward_description', $locale),
            'terms' => $program->text('terms', $locale),
            'rewards_available' => $rewards->map(fn (LoyaltyReward $reward): array => [
                'description' => $reward->description($locale),
                'expires_at' => $reward->expires_at?->toIso8601String(),
            ])->values()->all(),
        ]]);
    }
}
