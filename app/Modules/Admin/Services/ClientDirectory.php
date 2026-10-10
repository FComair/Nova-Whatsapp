<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Liste des clients du Super Admin (docs/31) : la requête de la liste, l'état d'abonnement dérivé et les chiffres d'en-tête.
 *
 * État d'abonnement (une seule définition, utilisée par la liste, les filtres, les rapports et la fiche) :
 *  - paused   : le compte est en pause ;
 *  - past_due : le dernier paiement a été refusé (abonnement ou compte « impayé ») ;
 *  - cancelled: l'abonnement est terminé (annulé, période payée écoulée) ;
 *  - active   : abonnement actif (même si une annulation est programmée à la fin de la période) ;
 *  - expired  : l'essai est fini sans abonnement (compte suspendu par le système) ;
 *  - trial    : essai en cours ;
 *  - none     : aucun abonnement ni essai (par exemple compte suspendu ou en suppression décidés par la plateforme).
 * La colonne « statut » du compte (actif, suspendu, en suppression…) est distincte : c'est l'état du compte, pas de la facturation.
 */
final class ClientDirectory
{
    public const STATES = ['trial', 'active', 'past_due', 'cancelled', 'expired', 'paused', 'none'];

    public const STATE_SQL = "case
        when organizations.status = 'paused' then 'paused'
        when sub.status = 'past_due' or organizations.status = 'past_due' then 'past_due'
        when sub.status = 'canceled' or organizations.suspension_reason = 'system.subscription_ended' then 'cancelled'
        when sub.id is not null and sub.status = 'active' then 'active'
        when organizations.suspension_reason = 'system.trial_ended' then 'expired'
        when organizations.status = 'trial' then 'trial'
        else 'none' end";

    /** Dernière activité significative : connexion d'un membre, rendez-vous créé ou message WhatsApp. */
    public const ACTIVITY_SQL = 'greatest(us.last_login_at, (select max(b.created_at) from bookings b where b.org_id = organizations.id), (select max(m.created_at) from whatsapp_messages m where m.org_id = organizations.id))';

    public const RENEWAL_SQL = "case when ({state}) = 'trial' then organizations.trial_ends_at when sub.id is not null then coalesce(sub.cancel_at, sub.current_period_end) end";

    /** Revenu mensuel récurrent : prix mensuel de la formule tant que l'abonnement court (actif ou impayé). */
    public const MRR_SQL = "case when ({state}) in ('active', 'past_due') then coalesce(pl.price_monthly_cents, 0) else 0 end";

    /**
     * @return Builder<PlatformOrganization>
     */
    public static function query(): Builder
    {
        $state = self::STATE_SQL;

        return PlatformOrganization::query()
            ->leftJoin('subscriptions as sub', 'sub.org_id', '=', 'organizations.id')
            ->leftJoin(DB::raw('plans pl'), DB::raw('pl.id'), '=', DB::raw('coalesce(sub.plan_id, organizations.plan_id)'))
            ->leftJoin(DB::raw('org_user_summary() us'), DB::raw('us.org_id'), '=', 'organizations.id')
            ->whereNull('organizations.deleted_at')
            ->select('organizations.*', 'sub.id as subscription_id', 'sub.status as subscription_status', 'pl.name as plan_name', 'pl.code as plan_code', 'pl.currency as plan_currency')
            ->addSelect(DB::raw("({$state}) as subscription_state"))
            ->addSelect(DB::raw('('.str_replace('{state}', $state, self::RENEWAL_SQL).') as renewal_at'))
            ->addSelect(DB::raw('('.str_replace('{state}', $state, self::MRR_SQL).') as mrr_cents'))
            ->addSelect('us.owner_email', 'us.owner_phone', 'us.members_count')
            ->addSelect(DB::raw(self::ACTIVITY_SQL.' as last_activity_at'))
            ->addSelect(DB::raw('(select string_agg(t.tag, \',\' order by t.tag) from client_tags t where t.org_id = organizations.id) as tag_list'));
    }

    /**
     * Chiffres d'en-tête (sur tous les clients, sans filtre).
     *
     * @return array{total: int, active: int, trial: int, past_due: int, cancelled: int, expired: int, paused: int, suspended: int, new_this_month: int, renewals_soon: int, mrr_cents: int}
     */
    public static function metrics(): array
    {
        $row = DB::connection(PlatformAudit::CONNECTION)->query()->fromSub(self::query()->toBase(), 'c')->selectRaw(
            "count(*) as total,
             count(*) filter (where subscription_state = 'active') as active,
             count(*) filter (where subscription_state = 'trial') as trial,
             count(*) filter (where subscription_state = 'past_due') as past_due,
             count(*) filter (where subscription_state = 'cancelled') as cancelled,
             count(*) filter (where subscription_state = 'expired') as expired,
             count(*) filter (where subscription_state = 'paused') as paused,
             count(*) filter (where status = 'suspended') as suspended,
             count(*) filter (where created_at >= date_trunc('month', now())) as new_this_month,
             count(*) filter (where subscription_state = 'active' and renewal_at is not null and renewal_at between now() and now() + interval '7 days') as renewals_soon,
             coalesce(sum(mrr_cents), 0) as mrr_cents"
        )->first();

        return [
            'total' => (int) $row->total, 'active' => (int) $row->active, 'trial' => (int) $row->trial, 'past_due' => (int) $row->past_due,
            'cancelled' => (int) $row->cancelled, 'expired' => (int) $row->expired, 'paused' => (int) $row->paused, 'suspended' => (int) $row->suspended,
            'new_this_month' => (int) $row->new_this_month, 'renewals_soon' => (int) $row->renewals_soon, 'mrr_cents' => (int) $row->mrr_cents,
        ];
    }
}
