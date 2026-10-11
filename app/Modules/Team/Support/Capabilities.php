<?php

declare(strict_types=1);

namespace App\Modules\Team\Support;

use App\Modules\Tenancy\Models\OrgMember;

/**
 * Capacités d'un membre de l'équipe d'un établissement (docs/34) : la source de vérité de ce que chaque personne peut voir et faire.
 *
 *  - Le rôle de base (`org_members.role`) donne les capacités PAR DÉFAUT, identiques à la matrice de docs/02 §1 : sans réglage, rien ne change.
 *  - Les réglages du membre (`org_members.permissions`) ne gardent que les ÉCARTS (capacité => vrai / faux) par rapport à ces défauts.
 *  - Le propriétaire a toujours tout ; ses droits ne se modifient pas.
 *  - Seules existent les capacités qui ouvrent ou ferment une fonction réellement présente : rien n'est affiché qui ne protège rien.
 */
final class Capabilities
{
    /** Groupes d'affichage, dans l'ordre. */
    public const GROUPS = [
        'dashboard' => ['dashboard.view'],
        'appointments' => ['calendar.view', 'appointments.view_all', 'appointments.create', 'appointments.edit', 'appointments.reschedule', 'appointments.cancel', 'appointments.complete', 'appointments.no_show', 'appointments.view_notes'],
        'services' => ['services.create', 'services.edit', 'services.delete', 'services.prices'],
        'schedules' => ['schedules.edit_own', 'calendars.own'],
        'customers' => ['customers.view_own', 'customers.view_all', 'customers.edit', 'customers.organize', 'customers.archive', 'customers.erase'],
        'whatsapp' => ['whatsapp.connect', 'whatsapp.templates'],
        'loyalty' => ['loyalty.view', 'loyalty.redeem', 'loyalty.adjust', 'loyalty.configure'],
        'reports' => ['reports.view', 'reports.all_data', 'reports.financial', 'reports.staff', 'reports.export', 'reports.configure'],
        'payments' => ['payments.record'],
        'settings' => ['settings.business', 'settings.practitioners', 'settings.integrations', 'settings.billing', 'settings.team'],
        'support' => ['support.use'],
    ];

    /** Capacités sensibles : seul le propriétaire peut les accorder (une personne qui les a ne peut pas les transmettre). */
    public const PROTECTED = ['settings.team', 'settings.billing'];

    /** Capacités qui en impliquent une autre : voir tous les clients suppose de voir les siens. */
    private const IMPLIES = ['customers.view_all' => ['customers.view_own'], 'reports.all_data' => ['reports.view']];

    /** Ce que chaque rôle de base peut faire par défaut (docs/02 §1). `reports.financial` du responsable suit le réglage du propriétaire. */
    private const DEFAULTS = [
        OrgMember::ROLE_SITE_MANAGER => [
            'dashboard.view', 'calendar.view', 'appointments.view_all', 'appointments.create', 'appointments.edit', 'appointments.reschedule', 'appointments.cancel', 'appointments.complete', 'appointments.no_show', 'appointments.view_notes',
            'services.create', 'services.edit', 'services.delete', 'services.prices', 'calendars.own',
            'customers.view_own', 'customers.view_all', 'customers.edit', 'customers.organize', 'customers.archive', 'customers.erase',
            'loyalty.view', 'loyalty.redeem', 'loyalty.adjust',
            'reports.view', 'reports.all_data', 'reports.staff', 'reports.export', 'payments.record', 'support.use',
        ],
        OrgMember::ROLE_PRACTITIONER => [
            'dashboard.view', 'calendar.view', 'appointments.create', 'appointments.edit', 'appointments.reschedule', 'appointments.cancel', 'appointments.complete', 'appointments.no_show', 'appointments.view_notes',
            'schedules.edit_own', 'calendars.own', 'customers.view_own', 'customers.edit', 'reports.view', 'payments.record', 'support.use',
        ],
        OrgMember::ROLE_RECEPTIONIST => [
            'dashboard.view', 'calendar.view', 'appointments.view_all', 'appointments.create', 'appointments.edit', 'appointments.reschedule', 'appointments.cancel', 'appointments.complete', 'appointments.no_show',
            'customers.view_own', 'customers.view_all', 'customers.edit', 'customers.organize', 'loyalty.view', 'loyalty.redeem', 'payments.record', 'support.use',
        ],
    ];

    /** Rôles qu'on peut donner à une nouvelle personne : « Praticien » et « Assistant » (le rôle historique de réceptionniste). */
    public const INVITABLE_ROLES = [OrgMember::ROLE_PRACTITIONER, OrgMember::ROLE_RECEPTIONIST];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    public static function exists(string $capability): bool
    {
        return in_array($capability, self::all(), true);
    }

    /**
     * Capacités par défaut d'un rôle de base.
     *
     * @return list<string>
     */
    public static function defaults(string $role, bool $managersSeeFinancial = false): array
    {
        if ($role === OrgMember::ROLE_OWNER) {
            return self::all();
        }

        $granted = self::DEFAULTS[$role] ?? [];

        if ($role === OrgMember::ROLE_SITE_MANAGER && $managersSeeFinancial) {
            $granted[] = 'reports.financial';
        }

        return $granted;
    }

    /**
     * Capacités effectives : défauts du rôle, puis écarts du membre, puis implications. Le propriétaire a tout.
     *
     * @param  array<string, bool>|null  $overrides
     * @return list<string>
     */
    public static function effective(string $role, ?array $overrides = null, bool $managersSeeFinancial = false): array
    {
        if ($role === OrgMember::ROLE_OWNER) {
            return self::all();
        }

        $granted = array_fill_keys(self::defaults($role, $managersSeeFinancial), true);

        foreach (self::clean($overrides) as $capability => $value) {
            if ($value) {
                $granted[$capability] = true;
            } else {
                unset($granted[$capability]);
            }
        }

        foreach (self::IMPLIES as $capability => $implied) {
            if (isset($granted[$capability])) {
                foreach ($implied as $other) {
                    $granted[$other] = true;
                }
            }
        }

        return array_values(array_filter(self::all(), fn (string $capability): bool => isset($granted[$capability])));
    }

    /**
     * Écarts à enregistrer pour que `$wanted` soit exactement l'ensemble des capacités du membre : on ne garde que ce qui diffère des défauts.
     *
     * @param  list<string>  $wanted
     * @return array<string, bool>
     */
    public static function overridesFor(string $role, array $wanted, bool $managersSeeFinancial = false): array
    {
        $defaults = self::defaults($role, $managersSeeFinancial);
        $wanted = array_values(array_intersect(self::all(), $wanted));
        $overrides = [];

        foreach (array_diff($wanted, $defaults) as $capability) {
            $overrides[$capability] = true;
        }

        foreach (array_diff($defaults, $wanted) as $capability) {
            $overrides[$capability] = false;
        }

        ksort($overrides);

        return $overrides;
    }

    /**
     * Garde seulement les capacités connues et les valeurs booléennes.
     *
     * @param  array<string, mixed>|null  $overrides
     * @return array<string, bool>
     */
    public static function clean(?array $overrides): array
    {
        $clean = [];

        foreach ($overrides ?? [] as $capability => $value) {
            if (self::exists($capability) && is_bool($value)) {
                $clean[$capability] = $value;
            }
        }

        return $clean;
    }
}
