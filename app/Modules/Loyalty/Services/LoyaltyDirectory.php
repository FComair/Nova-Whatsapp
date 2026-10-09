<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Lecture du programme de fidélité pour les écrans (docs/27) : résumé du programme, tableau de bord, liste des clients, fiche d'un client.
 * Statut d'un client dans le programme : non inscrit, en cours, récompense disponible, utilisée (plus rien en cours), inactif (a quitté le
 * programme, ou aucune visite depuis 180 jours sans récompense à utiliser).
 */
final class LoyaltyDirectory
{
    public const STATUSES = ['not_enrolled', 'in_progress', 'reward_available', 'redeemed', 'inactive'];

    /** Au-delà de ce délai sans visite éligible, un client sans récompense disponible est dit inactif. */
    public const INACTIVE_DAYS = 180;

    /** « Proche de la récompense » : il lui reste au plus ce nombre de visites. */
    public const CLOSE_WITHIN = 2;

    /** Programme montré par les écrans : celui en cours, sinon le dernier archivé (ses récompenses restent utilisables). */
    public function program(): ?LoyaltyProgram
    {
        return LoyaltyProgram::current() ?? LoyaltyProgram::query()->orderByDesc('created_at')->first();
    }

    /**
     * Le programme sous la forme d'un écran, textes dans la langue demandée.
     *
     * @return array<string, mixed>
     */
    public function summary(LoyaltyProgram $program, string $locale): array
    {
        return [
            'id' => $program->id,
            'status' => $program->status,
            'name' => $program->text('name', $locale),
            'reward_description' => $program->text('reward_description', $locale),
            'terms' => $program->text('terms', $locale),
            'required_visits' => $program->required_visits,
            'services_mode' => $program->services_mode,
            'earn_services' => $this->serviceNames($program->serviceIds('earn')),
            'min_amount_cents' => $program->min_amount_cents,
            'require_payment' => $program->require_payment,
            'auto_enroll' => $program->auto_enroll,
            'reward_type' => $program->reward_type,
            'reward_value' => $program->reward_value,
            'reward_max_cents' => $program->reward_max_cents,
            'reward_services_mode' => $program->reward_services_mode,
            'reward_services' => $this->serviceNames($program->serviceIds('reward')),
            'redeem_timing' => $program->redeem_timing,
            'combinable' => $program->combinable,
            'requires_appointment' => $program->requires_appointment,
            'expiry_days' => $program->expiry_days,
            'activated_at' => $program->activated_at?->toIso8601String(),
        ];
    }

    /**
     * Tableau de bord : chiffres du moment (pas de période : voir LoyaltyReport pour l'évolution).
     *
     * @return array<string, mixed>
     */
    public function overview(LoyaltyProgram $program): array
    {
        $members = LoyaltyMember::query()->where('program_id', $program->id);
        $available = $this->usableRewards();
        $required = $program->required_visits;

        return [
            'enrolled' => (clone $members)->where('status', LoyaltyMember::ACTIVE)->count(),
            'opted_out' => (clone $members)->where('status', LoyaltyMember::OPTED_OUT)->count(),
            'in_progress' => (clone $members)->where('status', LoyaltyMember::ACTIVE)->where('stamps', '>', 0)->count(),
            'rewards_earned' => LoyaltyReward::query()->where('program_id', $program->id)->where('status', '!=', LoyaltyReward::VOID)->count(),
            'rewards_redeemed' => LoyaltyReward::query()->where('program_id', $program->id)->where('status', LoyaltyReward::REDEEMED)->count(),
            'rewards_outstanding' => (clone $available)->where('program_id', $program->id)->count(),
            'rewards_expired' => LoyaltyReward::query()->where('program_id', $program->id)->where('status', LoyaltyReward::EXPIRED)->count(),
            'stamps_in_circulation' => (int) (clone $members)->where('status', LoyaltyMember::ACTIVE)->sum('stamps'),
            'close' => DB::table('loyalty_members as m')->join('customers as c', 'c.id', '=', 'm.customer_id')
                ->where('m.program_id', $program->id)->where('m.status', LoyaltyMember::ACTIVE)->whereNull('c.deleted_at')
                ->where('m.stamps', '>', 0)->where('m.stamps', '>=', max(1, $required - self::CLOSE_WITHIN))
                ->orderByDesc('m.stamps')->orderBy('c.name')->limit(8)
                ->get(['c.id', 'c.name', 'c.phone_e164', 'm.stamps'])
                ->map(fn (object $row): array => ['customer_id' => (string) $row->id, 'name' => $row->name, 'phone' => (string) $row->phone_e164, 'stamps' => (int) $row->stamps, 'remaining' => max(0, $required - (int) $row->stamps)])->all(),
        ];
    }

    /**
     * Activité récente (journal) : tampons, récompenses, ajustements, changements de configuration.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(?LoyaltyProgram $program, int $limit = 15, ?string $customerId = null): array
    {
        $rows = DB::table('loyalty_ledger as l')
            ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
            ->leftJoin('users as u', 'u.id', '=', 'l.actor_id')
            ->when($customerId !== null, fn (Builder $q) => $q->where('l.customer_id', $customerId))
            ->when($program !== null && $customerId === null, fn (Builder $q) => $q->where('l.program_id', $program?->id))
            ->orderByDesc('l.id')->limit($limit)
            ->get(['l.id', 'l.kind', 'l.stamps_delta', 'l.balance_after', 'l.reason', 'l.data', 'l.created_at', 'l.customer_id', 'c.name as customer_name', 'u.email as actor_name']);

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'delta' => (int) $row->stamps_delta,
            'balance' => $row->balance_after === null ? null : (int) $row->balance_after,
            'reason' => $row->reason,
            'detail' => $this->detail((string) $row->kind, $row->data),
            'at' => CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
            'customer_id' => $row->customer_id === null ? null : (string) $row->customer_id,
            'customer' => $row->customer_name,
            'actor' => $row->actor_name,
        ])->all();
    }

    /**
     * Liste filtrable des clients avec leur statut de fidélité.
     *
     * @param  array{status?: string|null, search?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, last: int}
     */
    public function customers(?LoyaltyProgram $program, array $filters, int $page): array
    {
        $programId = $program->id ?? '00000000-0000-0000-0000-000000000000';
        $required = $program->required_visits ?? 1;
        $usable = "(select count(*) from loyalty_rewards r where r.customer_id = c.id and r.status = 'available' and (r.expires_at is null or r.expires_at > now()))";
        $redeemed = "(select count(*) from loyalty_rewards r where r.customer_id = c.id and r.status = 'redeemed')";
        $inactive = self::INACTIVE_DAYS;
        $status = "case
            when m.id is null then 'not_enrolled'
            when m.status = 'opted_out' then 'inactive'
            when {$usable} > 0 then 'reward_available'
            when m.last_visit_at is not null and m.last_visit_at < now() - interval '{$inactive} days' then 'inactive'
            when m.stamps = 0 and {$redeemed} > 0 then 'redeemed'
            else 'in_progress' end";

        $query = DB::table('customers as c')
            ->leftJoin('loyalty_members as m', fn ($join) => $join->on('m.customer_id', '=', 'c.id')->where('m.program_id', '=', $programId))
            ->whereNull('c.deleted_at')->whereNull('c.anonymized_at')->where('c.status', '!=', 'archived')
            ->select('c.id', 'c.name', 'c.phone_e164', 'm.stamps', 'm.last_visit_at', 'm.status as member_status')
            ->selectRaw("{$usable} as rewards_available")
            ->selectRaw("{$status} as loyalty_status");

        if (($filters['search'] ?? '') !== '') {
            $like = '%'.addcslashes((string) $filters['search'], '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('c.name', 'ilike', $like)->orWhere('c.phone_e164', 'like', $like));
        }

        if (in_array($filters['status'] ?? null, self::STATUSES, true)) {
            $query->whereRaw("({$status}) = ?", [$filters['status']]);
        }

        $paginator = $query->orderByRaw('m.last_visit_at desc nulls last')->orderBy('c.name')->paginate(25, ['*'], 'page', $page);

        $rows = [];

        foreach ($paginator->items() as $row) {
            $rows[] = [
                'customer_id' => (string) $row->id,
                'name' => $row->name,
                'phone' => (string) $row->phone_e164,
                'stamps' => $row->stamps === null ? null : (int) $row->stamps,
                'required' => $required,
                'rewards_available' => (int) $row->rewards_available,
                'last_visit_at' => $row->last_visit_at === null ? null : CarbonImmutable::parse((string) $row->last_visit_at)->toIso8601String(),
                'status' => (string) $row->loyalty_status,
            ];
        }

        return ['rows' => $rows, 'total' => $paginator->total(), 'page' => $paginator->currentPage(), 'last' => $paginator->lastPage()];
    }

    /**
     * Fiche d'un client : participation, récompenses (avec ce qui est utilisable), historique.
     *
     * @return array<string, mixed>
     */
    public function panel(Customer $customer, ?LoyaltyProgram $program, string $locale, bool $withHistory = true): array
    {
        $member = $program === null ? null : LoyaltyMember::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->first();
        $rewards = LoyaltyReward::query()->where('customer_id', $customer->id)->orderByRaw("case status when 'available' then 0 else 1 end")->orderByDesc('earned_at')->limit(30)->get();
        $required = $program->required_visits ?? 0;

        return [
            'program' => $program === null ? null : ['id' => $program->id, 'status' => $program->status, 'name' => $program->text('name', $locale), 'required_visits' => $program->required_visits, 'reward_description' => $program->text('reward_description', $locale), 'terms' => $program->text('terms', $locale), 'auto_enroll' => $program->auto_enroll],
            'member' => $member === null ? null : [
                'status' => $member->status,
                'stamps' => $member->stamps,
                'required' => $required,
                'remaining' => max(0, $required - $member->stamps),
                'lifetime' => $member->lifetime_stamps,
                'rewards_earned' => $member->rewards_earned,
                'last_visit_at' => $member->last_visit_at?->toIso8601String(),
                'joined_at' => $member->joined_at->toIso8601String(),
                'joined_via' => $member->joined_via,
            ],
            'rewards' => $rewards->map(fn (LoyaltyReward $reward): array => [
                'id' => $reward->id,
                'status' => $reward->status === LoyaltyReward::AVAILABLE && ! $reward->isUsable() ? LoyaltyReward::EXPIRED : $reward->status,
                'description' => $reward->description($locale),
                'terms' => (string) (($reward->snapshot['terms'][$locale] ?? '') ?: ($reward->snapshot['terms']['fr'] ?? '')),
                'type' => $reward->snapshot['type'] ?? 'custom',
                'earned_at' => $reward->earned_at->toIso8601String(),
                'expires_at' => $reward->expires_at?->toIso8601String(),
                'redeemed_at' => $reward->redeemed_at?->toIso8601String(),
                'applied_value_cents' => $reward->applied_value_cents,
                'booking_id' => $reward->redeemed_booking_id,
                'earned_booking_id' => $reward->earned_booking_id,
                'value' => $reward->snapshot['value'] ?? null,
                'max_cents' => $reward->snapshot['max_cents'] ?? null,
                'service_ids' => $reward->snapshot['service_ids'] ?? null,
                'redeem_timing' => $reward->snapshot['redeem_timing'] ?? 'next_visit',
                'requires_appointment' => (bool) ($reward->snapshot['requires_appointment'] ?? true),
                'combinable' => (bool) ($reward->snapshot['combinable'] ?? false),
            ])->all(),
            'history' => $withHistory ? $this->recent(null, 20, $customer->id) : [],
        ];
    }

    /**
     * Rendez-vous du client sur lesquels une récompense peut s'appliquer (confirmés ou terminés, récents ou à venir), avec la récompense déjà utilisée.
     *
     * @return list<array<string, mixed>>
     */
    public function redeemableBookings(Customer $customer): array
    {
        $timezone = (string) (DB::table('locations')->orderBy('created_at')->value('timezone') ?: 'UTC');
        $bookings = Booking::query()->where('customer_id', $customer->id)->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])
            ->where('starts_at', '>', CarbonImmutable::now()->subDays(45))->orderByDesc('starts_at')->limit(15)->get();
        $services = DB::table('services')->whereIn('id', $bookings->pluck('service_id'))->pluck('name', 'id');
        $used = DB::table('loyalty_rewards')->where('status', LoyaltyReward::REDEEMED)->whereIn('redeemed_booking_id', $bookings->pluck('id'))->pluck('id', 'redeemed_booking_id');

        return $bookings->map(fn (Booking $booking): array => [
            'id' => $booking->id,
            'date' => $booking->starts_at->setTimezone($timezone)->toDateString(),
            'time' => $booking->starts_at->setTimezone($timezone)->format('H:i'),
            'service_id' => $booking->service_id,
            'service' => $services[$booking->service_id] ?? null,
            'price_cents' => $booking->price_cents,
            'status' => $booking->status,
            'payment_status' => $booking->payment_status,
            'has_reward' => isset($used[$booking->id]),
            'earning' => DB::table('loyalty_rewards')->where('earned_booking_id', $booking->id)->exists(),
        ])->all();
    }

    /**
     * Progression affichée dans les rendez-vous : une ligne par client concerné.
     *
     * @param  list<string>  $customerIds
     * @param  list<string>  $bookingIds
     * @return array<string, array<string, mixed>> par identifiant de rendez-vous : tampon gagné ici, progression, récompense appliquée ou disponible
     */
    public function forBookings(?LoyaltyProgram $program, array $customerIds, array $bookingIds): array
    {
        if ($program === null || $bookingIds === []) {
            return [];
        }

        $members = DB::table('loyalty_members')->where('program_id', $program->id)->whereIn('customer_id', $customerIds)->get()->keyBy('customer_id');
        $earned = DB::table('loyalty_ledger')->where('program_id', $program->id)->where('kind', 'stamp_earned')->whereIn('booking_id', $bookingIds)->pluck('booking_id')->flip();
        $reversed = DB::table('loyalty_ledger')->where('program_id', $program->id)->where('kind', 'stamp_reversed')->whereIn('booking_id', $bookingIds)->pluck('booking_id')->flip();
        $applied = DB::table('loyalty_rewards')->where('status', LoyaltyReward::REDEEMED)->whereIn('redeemed_booking_id', $bookingIds)->get(['redeemed_booking_id', 'applied_value_cents'])->keyBy('redeemed_booking_id');
        $available = DB::table('loyalty_rewards')->where('status', LoyaltyReward::AVAILABLE)->whereIn('customer_id', $customerIds)->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->select('customer_id')->selectRaw('count(*) as n')->groupBy('customer_id')->pluck('n', 'customer_id');
        $bookings = DB::table('bookings')->whereIn('id', $bookingIds)->pluck('customer_id', 'id');

        $rows = [];

        foreach ($bookings as $bookingId => $customerId) {
            $member = $members[$customerId] ?? null;
            $rows[(string) $bookingId] = [
                'stamps' => $member === null ? null : (int) $member->stamps,
                'required' => $program->required_visits,
                'enrolled' => $member !== null && $member->status === LoyaltyMember::ACTIVE,
                'earned_here' => isset($earned[$bookingId]) && ! isset($reversed[$bookingId]),
                'reward_applied_cents' => isset($applied[$bookingId]) ? (int) $applied[$bookingId]->applied_value_cents : null,
                'rewards_available' => (int) ($available[$customerId] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Recherche d'un client à l'accueil (nom ou téléphone) pour utiliser une récompense : ceux qui ont une récompense disponible d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term): array
    {
        $like = '%'.addcslashes(trim($term), '\\%_').'%';
        $usable = "(select count(*) from loyalty_rewards r where r.customer_id = c.id and r.status = 'available' and (r.expires_at is null or r.expires_at > now()))";

        return DB::table('customers as c')->whereNull('c.deleted_at')->whereNull('c.anonymized_at')->where('c.status', '!=', 'archived')
            ->where(fn (Builder $q) => $q->where('c.name', 'ilike', $like)->orWhere('c.phone_e164', 'like', $like))
            ->select('c.id', 'c.name', 'c.phone_e164')->selectRaw("{$usable} as rewards")
            ->orderByRaw("{$usable} desc")->orderBy('c.name')->limit(10)->get()
            ->map(fn (object $row): array => ['customer_id' => (string) $row->id, 'name' => $row->name, 'phone' => (string) $row->phone_e164, 'rewards' => (int) $row->rewards])->all();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<LoyaltyReward> */
    private function usableRewards(): \Illuminate\Database\Eloquent\Builder
    {
        return LoyaltyReward::query()->where('status', LoyaltyReward::AVAILABLE)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * @param  list<string>|null  $ids
     * @return list<string>
     */
    private function serviceNames(?array $ids): array
    {
        return $ids === null ? [] : DB::table('services')->whereIn('id', $ids)->orderBy('name')->pluck('name')->map(fn ($name): string => (string) $name)->all();
    }

    /**
     * Détail lisible d'une ligne du journal (jamais de donnée personnelle).
     *
     * @return array<string, mixed>|null
     */
    private function detail(string $kind, mixed $data): ?array
    {
        $decoded = is_string($data) ? json_decode($data, true) : (is_array($data) ? $data : null);

        if (! is_array($decoded)) {
            return null;
        }

        return match ($kind) {
            'reward_redeemed' => ['value_cents' => $decoded['value_cents'] ?? null],
            'config_changed' => ['fields' => array_keys((array) ($decoded['changes'] ?? [])), 'created' => (bool) ($decoded['created'] ?? false), 'messages' => isset($decoded['messages'])],
            'status_changed' => ['from' => $decoded['from'] ?? null, 'to' => $decoded['to'] ?? null],
            'message_sent' => ['message' => $decoded['message'] ?? null],
            'stamp_reversed' => ['shortfall' => $decoded['shortfall'] ?? null],
            default => null,
        };
    }
}
