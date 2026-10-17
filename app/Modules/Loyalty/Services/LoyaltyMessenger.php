<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\ConsentRecorder;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use App\Modules\Loyalty\Support\LoyaltyMessages;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Services\MessageSender;
use App\Modules\Whatsapp\Services\WhatsappUsage;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Messages WhatsApp de fidélité (docs/27). Règles :
 * - chaque message est FACULTATIF et désactivé tant que l'établissement ne l'a pas activé, occasion par occasion ;
 * - ils sont promotionnels par nature : envoyés seulement à un client qui a donné son accord marketing WhatsApp (jamais déduit de
 *   l'inscription au programme), actif, non bloqué, sans STOP ; la participation au programme et le consentement restent deux choses ;
 * - langue du client (français par défaut) ; texte de l'établissement quand la fenêtre de service de 24 h est ouverte ; sinon
 *   modèle approuvé par Meta, dont le texte est fixe (mêmes informations, mots de la plateforme) ;
 * - au mieux : un refus ou une panne n'empêche jamais l'action en cours.
 */
final class LoyaltyMessenger
{
    public function __construct(private readonly MessageSender $sender, private readonly ConsentRecorder $consent) {}

    public function joined(LoyaltyProgram $program, Customer $customer, LoyaltyMember $member): bool
    {
        return $this->send('joined', $program, $customer, $member, null);
    }

    public function progress(LoyaltyProgram $program, Customer $customer, LoyaltyMember $member): bool
    {
        return $this->send('progress', $program, $customer, $member, null);
    }

    public function rewardEarned(LoyaltyProgram $program, Customer $customer, LoyaltyReward $reward): bool
    {
        return $this->send('reward_earned', $program, $customer, $this->member($program, $customer), $reward);
    }

    public function rewardRedeemed(LoyaltyProgram $program, Customer $customer, LoyaltyReward $reward): bool
    {
        return $this->send('reward_redeemed', $program, $customer, $this->member($program, $customer), $reward);
    }

    public function expiryReminder(LoyaltyProgram $program, Customer $customer, LoyaltyReward $reward): bool
    {
        return $this->send('expiry_reminder', $program, $customer, $this->member($program, $customer), $reward);
    }

    /**
     * Variables d'un message pour un client.
     *
     * @return array<string, string>
     */
    public function variables(LoyaltyProgram $program, Customer $customer, ?LoyaltyMember $member, ?LoyaltyReward $reward, string $language, string $business): array
    {
        $stamps = $member->stamps ?? 0;
        $required = $program->required_visits;
        $expires = $reward?->expires_at;

        return [
            'name' => $this->firstName($customer),
            'business' => $business,
            'program' => $program->text('name', $language),
            'progress' => "{$stamps}/{$required}",
            'stamps' => (string) $stamps,
            'required' => (string) $required,
            'remaining' => (string) max(0, $required - $stamps),
            'reward' => $reward?->description($language) ?? $program->text('reward_description', $language),
            'expiry_date' => $expires === null ? '' : $expires->locale($language)->isoFormat('DD/MM/YYYY'),
        ];
    }

    private function send(string $kind, LoyaltyProgram $program, Customer $customer, ?LoyaltyMember $member, ?LoyaltyReward $reward): bool
    {
        try {
            $organization = Organization::query()->find($program->org_id);

            if ($organization === null || ! (bool) ($program->messages[$kind]['enabled'] ?? false) || ! $this->allowed($customer, $member)) {
                return false;
            }

            $language = in_array($customer->language, (array) config('nova.locales'), true) ? (string) $customer->language : (string) config('app.fallback_locale');
            $values = $this->variables($program, $customer, $member, $reward, $language, $organization->name);

            try {
                if ($this->sender->windowOpen($customer)) {
                    $this->sender->sendText($organization, $customer, LoyaltyMessages::render(LoyaltyMessages::text($program->messages, $kind, $language), $values));
                } else {
                    $this->sender->sendTemplate(
                        $organization,
                        $customer,
                        (string) config("whatsapp.loyalty_templates.{$kind}"),
                        $language,
                        [$values['business'], $values['name'] !== '' ? $values['name'] : '-', $values['progress'], $values['reward'], $values['expiry_date'] !== '' ? $values['expiry_date'] : '-'],
                        WhatsappUsage::MARKETING,
                    );
                }
            } catch (SendRefused|TransportFailed) {
                return false;
            }

            DB::table('loyalty_ledger')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $program->org_id,
                'program_id' => $program->id,
                'customer_id' => $customer->id,
                'kind' => LoyaltyLedgerEntry::MESSAGE_SENT,
                'stamps_delta' => 0,
                'reward_id' => $reward?->id,
                'actor_type' => 'system',
                'data' => json_encode(['message' => $kind], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Client joignable pour un message promotionnel et encore dans le programme. */
    private function allowed(Customer $customer, ?LoyaltyMember $member): bool
    {
        return ($member === null || $member->isActive()) && $customer->anonymized_at === null && $this->consent->canSendMarketing($customer);
    }

    private function member(LoyaltyProgram $program, Customer $customer): ?LoyaltyMember
    {
        return LoyaltyMember::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->first();
    }

    private function firstName(Customer $customer): string
    {
        $name = trim((string) ($customer->first_name ?? $customer->listName() ?? $customer->whatsapp_profile_name ?? ''));

        return $name === '' ? '' : (string) strtok($name, ' ');
    }
}
