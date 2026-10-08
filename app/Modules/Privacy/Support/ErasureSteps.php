<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Support;

/**
 * Étapes de l'effacement d'une organisation, dans l'ordre (les enfants avant les parents : plusieurs clés étrangères ne cascadent pas).
 *
 * Règle : TOUTE table portant `org_id` doit figurer ici (un test le vérifie) ; sinon une nouvelle table garderait des données
 * personnelles après « suppression ». L'étape `users` supprime les comptes qui n'appartiennent qu'à cette organisation.
 * Le journal d'audit est effacé sauf les actions du Super Admin sur le compte, gardées comme preuve de la suppression.
 *
 * La même liste blanche existe dans la fonction SQL `gdpr_purge_org` (migration 2026_10_13) ; un test exécute chaque étape.
 */
final class ErasureSteps
{
    /**
     * Tables gardées telles quelles (obligation comptable de 7 ans, aucune donnée de carte). Les factures sont pseudonymisées par
     * l'étape invoices ; les lignes, paiements et abonnements ne contiennent aucune donnée personnelle.
     */
    public const RETAINED = ['subscriptions', 'invoice_lines', 'payments', 'whatsapp_usage'];

    public const ALL = [
        'booking_events',
        'price_list_imports',
        'whatsapp_messages',
        'external_busy_slots',
        'external_calendars',
        'calendar_feeds',
        'bookings',
        'practitioner_time_off',
        'practitioner_schedules',
        'practitioner_services',
        'invitations',
        'practitioners',
        'customers',
        'services',
        'service_categories',
        'business_hours_exceptions',
        'business_hours',
        'org_member_locations',
        'locations',
        'api_clients',
        'whatsapp_qr_codes',
        'whatsapp_templates',
        'whatsapp_accounts',
        'booking_policies',
        'onboarding_progress',
        'dunning_events',
        'payment_methods',
        'invoices',
        'users',
        'org_members',
        'audit_logs',
    ];
}
