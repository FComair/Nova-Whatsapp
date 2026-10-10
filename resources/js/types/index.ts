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
    /** Date d'envoi du logo (horodatage) : sert de numéro de version ; null = pas de logo. */
    logo_version: number | null;
    role: 'owner' | 'site_manager' | 'practitioner' | 'receptionist' | null;
    /** Capacités du membre (docs/34) : l'interface masque ce qui serait refusé, le serveur reste seul juge. */
    permissions: string[];
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
    photo_version: number | null;
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
    /** Photo choisie à la création (envoyée avec le formulaire). */
    photo?: File | null;
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

/** Pourboire d'un rendez-vous et sa répartition entre praticiens (la somme des parts est égale au pourboire). */
export interface BookingTip {
    amount_cents: number;
    paid_at: string;
    allocations: { practitioner_id: string; practitioner_name: string | null; amount_cents: number }[];
}

/** Rendez-vous du planning. Les minutes sont comptées depuis minuit, à l'heure locale du site. */
export interface PlanningBooking {
    id: string;
    practitioner_id: string;
    /** Nom du praticien, même devenu inactif ou supprimé ; null si introuvable. */
    practitioner_name: string | null;
    service_id: string;
    /** Jour du rendez-vous, « AAAA-MM-JJ », à l'heure locale du site. */
    date: string;
    customer: { id: string; name: string | null; phone: string | null };
    service: string | null;
    starts: string;
    ends: string;
    start_minute: number;
    end_minute: number;
    status: BookingStatus;
    price_cents: number;
    /** Devise de l'établissement pour ce rendez-vous (prix, encaissé et pourboire). */
    currency: string;
    payment_status: string;
    /** Encaissé pour le SERVICE : ne contient jamais le pourboire. */
    amount_collected_cents: number;
    /** Pourboire, à part du prix et de l'encaissé, avec sa répartition ; null s'il n'y en a pas. */
    tip: BookingTip | null;
    /** Fidélité du client pour ce rendez-vous (docs/27) ; null sans programme. */
    loyalty?: import('./loyalty').BookingLoyalty | null;
    notes: string | null;
    is_past: boolean;
}

export interface PlanningPractitioner {
    id: string;
    name: string;
    color: string;
    /** Version de la photo de profil (null : initiales). */
    photo_version: number | null;
    /** Jours et heures de travail du profil, par jour ISO de la semaine (« 1 » = lundi … « 7 » = dimanche). */
    schedule: Record<string, { start: number; end: number }[]>;
}

/** Semaine affichée par le planning (lundi → dimanche) avec les horaires d'ouverture de chaque jour. */
export interface PlanningWeek {
    start: string;
    end: string;
    days: { date: string; open: { start: number; end: number }[] }[];
}

export interface PlanningService {
    id: string;
    name: string;
    duration_minutes: number;
    practitioner_ids: string[];
}

/** Une tâche de mise en route (Réglages > Démarrage). `href` vaut null tant que l'écran n'existe pas (« bientôt »). */
export interface SetupStepData {
    key: string;
    state: 'done' | 'current' | 'todo' | 'soon';
    optional: boolean;
    href: string | null;
    /** Vrai pour une étape de l'assistant d'onboarding (son écran permet de modifier), faux pour une tâche détectée dans les données. */
    wizard: boolean;
}

export interface SetupCategoryData {
    key: 'business' | 'team' | 'messages' | 'launch';
    steps: SetupStepData[];
    total: number;
    done: number;
    left: number;
    complete: boolean;
}

/** Propriétés partagées avec toutes les pages (voir HandleInertiaRequests). */
export interface SharedProps {
    locale: Locale;
    locales: Locale[];
    translations: Record<string, unknown>;
    auth: { user: AuthUser | null; organization: AuthOrganization | null };
    /** Pastilles du menu Réglages ; null hors des pages de réglages. */
    settings: { whatsapp_connected: boolean; two_factor: boolean; setup: { key: string; left: number; complete: boolean }[] | null } | null;
    /** Documents juridiques à accepter (docs/28) : jamais acceptés (avis simple) ou nouvelle version significative (bloquante). */
    legal?: { pending: { code: string; slug: string; title: string; version: string; blocking: boolean }[] } | null;
    /** Pastille du menu Aide : tickets qui attendent l'attention de la personne. */
    support: { attention: number } | null;
    /** Catégories du centre d'aide dans la langue du client (docs/30) ; null hors des pages d'aide. */
    helpCategories?: Record<string, { name: string; description: string; icon: string }> | null;
    flash: { status: string | null; warning: string | null; first_login: boolean; suggestions: Suggestion[] };
    [key: string]: unknown;
}
