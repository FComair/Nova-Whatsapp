/** Types du programme de fidélité (docs/27), alignés sur LoyaltyDirectory et les contrôleurs de app/Modules/Loyalty. */

export type ProgramStatus = 'draft' | 'active' | 'paused' | 'archived';
export type MemberStatus = 'not_enrolled' | 'in_progress' | 'reward_available' | 'redeemed' | 'inactive';
export type RewardStatus = 'available' | 'redeemed' | 'expired' | 'void';
export type RewardType = 'free_service' | 'percent_discount' | 'fixed_amount' | 'custom';

export interface LoyaltyAbilities {
    view: boolean;
    configure: boolean;
    redeem: boolean;
    adjust: boolean;
}

/** Programme tel que l'écran le montre (textes dans la langue de l'utilisateur). */
export interface ProgramSummary {
    id: string;
    status: ProgramStatus;
    name: string;
    reward_description: string;
    terms: string;
    required_visits: number;
    services_mode: 'all' | 'selected';
    earn_services: string[];
    min_amount_cents: number | null;
    require_payment: boolean;
    auto_enroll: boolean;
    reward_type: RewardType;
    reward_value: number | null;
    reward_max_cents: number | null;
    reward_services_mode: 'all' | 'selected';
    reward_services: string[];
    redeem_timing: 'next_visit' | 'any_time';
    combinable: boolean;
    requires_appointment: boolean;
    expiry_days: number | null;
    activated_at: string | null;
}

export interface ActivityEntry {
    id: string;
    kind: string;
    delta: number;
    balance: number | null;
    reason: string | null;
    detail: Record<string, unknown> | null;
    at: string;
    customer_id: string | null;
    customer: string | null;
    actor: string | null;
}

export interface RewardRow {
    id: string;
    status: RewardStatus;
    description: string;
    terms: string;
    type: RewardType;
    earned_at: string;
    expires_at: string | null;
    redeemed_at: string | null;
    applied_value_cents: number | null;
    booking_id: string | null;
    earned_booking_id: string | null;
    value: number | null;
    max_cents: number | null;
    service_ids: string[] | null;
    redeem_timing: 'next_visit' | 'any_time';
    requires_appointment: boolean;
    combinable: boolean;
}

export interface MemberInfo {
    status: 'active' | 'opted_out';
    stamps: number;
    required: number;
    remaining: number;
    lifetime: number;
    rewards_earned: number;
    last_visit_at: string | null;
    joined_at: string;
    joined_via: 'auto' | 'staff';
}

export interface CustomerPanelData {
    program: {
        id: string;
        status: ProgramStatus;
        name: string;
        required_visits: number;
        reward_description: string;
        terms: string;
        auto_enroll: boolean;
    } | null;
    member: MemberInfo | null;
    rewards: RewardRow[];
    history: ActivityEntry[];
}

export interface RedeemableBooking {
    id: string;
    date: string;
    time: string;
    service_id: string;
    service: string | null;
    price_cents: number;
    status: string;
    payment_status: string;
    has_reward: boolean;
    earning: boolean;
}

export interface BookingLoyalty {
    stamps: number | null;
    required: number;
    enrolled: boolean;
    earned_here: boolean;
    reward_applied_cents: number | null;
    rewards_available: number;
}

/** Valeurs du formulaire de configuration (montants en centimes côté serveur). */
export interface ProgramValues {
    name: Record<string, string>;
    reward_description: Record<string, string>;
    terms: Record<string, string>;
    required_visits: number;
    services_mode: 'all' | 'selected';
    earn_services: string[];
    min_amount_cents: number | null;
    require_payment: boolean;
    auto_enroll: boolean;
    reward_type: RewardType;
    reward_value: number | null;
    reward_max_cents: number | null;
    reward_services_mode: 'all' | 'selected';
    reward_services: string[];
    redeem_timing: 'next_visit' | 'any_time';
    combinable: boolean;
    requires_appointment: boolean;
    expiry_days: number | null;
    expiry_reminder_days: number;
}

export interface ImpactData {
    fields: string[];
    members: number;
    in_progress: number;
    required_from: number;
    required_to: number;
    rewards_now: number;
    further_away: number;
    available_rewards: number;
    reward_changed: boolean;
    earn_rule_changed: boolean;
    active: boolean;
}

export interface LoyaltyReportData {
    period: { from: string; to: string; granularity: 'day' | 'week' | 'month'; timezone: string };
    filters: { service: string | null; location: string | null; program: string | null };
    scoped: boolean;
    metrics: {
        enrolled: number;
        stamps_earned: number;
        stamps_reversed: number;
        stamps_net: number;
        rewards_earned: number;
        rewards_redeemed: number;
        rewards_outstanding: number;
        redemption_rate: number | null;
        redemption_rate_base: number;
    };
    series: { key: string; earned: number; redeemed: number }[];
    returning: { enrolled: ReturningGroup; others: ReturningGroup };
    programs: { id: string; status: ProgramStatus; name: string; created_at: string }[];
}

export interface ReturningGroup {
    customers: number;
    repeat: number;
    rate: number | null;
}
