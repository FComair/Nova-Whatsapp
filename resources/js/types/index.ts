export type Locale = 'fr' | 'nl' | 'en';

export interface AuthUser {
    id: string;
    email: string;
    locale: Locale;
}

export interface AuthOrganization {
    id: string;
    name: string;
    status: 'trial' | 'active' | 'past_due' | 'suspended' | 'paused' | 'pending_deletion' | 'deleted';
    role: 'owner' | 'site_manager' | 'practitioner' | 'receptionist' | null;
}

export interface WizardStep {
    key: string;
    number: number;
    slug: string;
    status: 'done' | 'current' | 'todo';
    available: boolean;
}

/** État de l'assistant d'onboarding, fourni par OnboardingController. */
export interface Wizard {
    steps: WizardStep[];
    current: string;
    step: string;
    total: number;
}

/** Service tel que renvoyé par le serveur (montants en centimes). */
export interface ServiceItem {
    id: string;
    name: string;
    category: string | null;
    description: string | null;
    duration_minutes: number;
    buffer_before_minutes: number;
    buffer_after_minutes: number;
    price_cents: number;
    vat_rate: number;
    capacity: number;
    bookable_online: boolean;
    notes_required: boolean;
}

/** Champs du formulaire de service : les nombres sont saisis sous forme de texte. */
export interface ServiceFormData {
    name: string;
    category: string;
    description: string;
    duration_minutes: string;
    buffer_before_minutes: string;
    buffer_after_minutes: string;
    price: string;
    vat_rate: string;
    capacity: string;
    bookable_online: boolean;
    notes_required: boolean;
}

/** Praticien tel que renvoyé par le serveur. `schedules` : plages par site puis par jour ISO (1 = lundi). */
export interface PractitionerItem {
    id: string;
    name: string;
    bio: string | null;
    color: string;
    status: 'active' | 'draft' | 'inactive';
    is_me: boolean;
    bookable: boolean;
    has_account: boolean;
    invitation: { email: string; status: 'pending' | 'expired'; expires_at: string } | null;
    service_ids: string[];
    schedules: Record<string, Record<string, { start: string; end: string }[]>>;
}

export interface PractitionerFormData {
    name: string;
    color: string;
    bio: string;
    service_ids: string[];
    is_me: boolean;
    copy_hours: boolean;
}

/** Créneau libre proposé quand celui demandé n'est plus disponible (E10), à l'heure locale du site. */
export interface Suggestion {
    date: string;
    time: string;
    practitioner_id: string;
}

export type BookingStatus = 'pending' | 'confirmed' | 'completed' | 'no_show';

/** Rendez-vous du planning. Les minutes sont comptées depuis minuit, à l'heure locale du site. */
export interface PlanningBooking {
    id: string;
    practitioner_id: string;
    customer: { id: string; name: string | null; phone: string | null };
    service: string | null;
    starts: string;
    ends: string;
    start_minute: number;
    end_minute: number;
    status: BookingStatus;
    price_cents: number;
    payment_status: string;
    amount_collected_cents: number;
    notes: string | null;
    is_past: boolean;
}

export interface PlanningPractitioner {
    id: string;
    name: string;
    color: string;
    working: { start: number; end: number }[];
}

export interface PlanningService {
    id: string;
    name: string;
    duration_minutes: number;
    practitioner_ids: string[];
}

/** Propriétés partagées avec toutes les pages (voir HandleInertiaRequests). */
export interface SharedProps {
    locale: Locale;
    locales: Locale[];
    translations: Record<string, unknown>;
    auth: { user: AuthUser | null; organization: AuthOrganization | null };
    flash: { status: string | null; warning: string | null; first_login: boolean; suggestions: Suggestion[] };
    [key: string]: unknown;
}
