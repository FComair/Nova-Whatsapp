<?php

declare(strict_types=1);

use App\Modules\Billing\Contracts\PaymentGateway;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Plan;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Écran « Facturation » : parcours de souscription (prestataire simulé), changement de formule, annulation, identité, factures.
 */
beforeEach(function (): void {
    Notification::fake();
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['name' => 'Salon Aurore', 'status' => 'trial', 'trial_ends_at' => now()->addDays(10), 'country' => 'BE']);
    $this->gateway = app(PaymentGateway::class);

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->member = fn (string $role): User => $this->asOwner(function () use ($role): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);

        return $user;
    });
    $this->orgRow = fn () => DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->first();

    /** Parcourt le choix de formule puis le retour du prestataire simulé. */
    $this->subscribe = function (string $plan = 'basic', string $card = 'ok') {
        $redirect = ($this->as)()->post('/facturation/abonnement', ['plan' => $plan]);
        $ref = explode('ref=', (string) $redirect->headers->get('Location'))[1] ?? '';

        $return = ($this->as)()->post('/facturation/simulation', ['ref' => $ref, 'card' => $card]);

        return ($this->as)()->get((string) $return->headers->get('Location'));
    };
});

describe('accès', function (): void {
    it('affiche formules, essai et état au propriétaire', function (): void {
        ($this->as)()->get('/facturation')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('status', 'trial')
            ->where('subscription', null)
            ->where('hasActiveSubscription', false)
            ->where('vatRate', 21)
            ->has('plans', 2)
            ->where('plans.0.code', 'basic')->where('plans.0.price_cents', 2999)
            ->where('plans.1.code', 'business')->where('plans.1.price_cents', 4999)
            ->where('identity.billing_name', ''));
    });

    it('est réservé au propriétaire', function (string $role): void {
        $user = ($this->member)($role);

        ($this->as)($user)->get('/facturation')->assertForbidden();
        ($this->as)($user)->post('/facturation/abonnement', ['plan' => 'basic'])->assertForbidden();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('reste accessible à un compte suspendu faute de paiement, et propose de régulariser', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended', 'suspension_reason' => 'system.trial_ended'])->save());

        ($this->as)()->get('/facturation')->assertOk()->assertInertia(fn (Assert $page) => $page->where('status', 'suspended')->where('suspensionReason', 'system.trial_ended'));
        ($this->as)()->get('/planning')->assertOk();   // lecture seule : la lecture reste possible
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/facturation')->assertRedirect('/connexion');
    });
});

describe('souscription', function (): void {
    it('mène à la page du prestataire, puis active l\'abonnement au retour', function (): void {
        $response = ($this->as)()->post('/facturation/abonnement', ['plan' => 'business']);

        expect($response->headers->get('Location'))->toContain('/facturation/simulation?ref=seti_');

        ($this->subscribe)('business')->assertRedirect('/facturation')->assertSessionHas('status', 'Abonnement activé. Merci !');

        expect(DB::connection('pgsql_owner')->table('subscriptions')->first())->status->toBe('active')
            ->and(($this->orgRow)())->status->toBe('active')->plan_id->toBe(Plan::query()->where('code', 'business')->value('id'))
            ->and(DB::connection('pgsql_owner')->table('invoices')->value('subtotal_cents'))->toBe(4999);

        ($this->as)()->get('/facturation')->assertInertia(fn (Assert $page) => $page
            ->where('subscription.plan', 'business')->where('hasActiveSubscription', true)->where('method.last4', '4242')->has('invoices', 1)->where('invoices.0.status', 'paid'));
    });

    it('explique un refus de carte et n\'enregistre rien', function (): void {
        ($this->subscribe)('basic', 'declined')->assertRedirect('/facturation')->assertSessionHasErrors(['billing' => 'Le paiement a été refusé. Vérifiez votre moyen de paiement ou essayez une autre carte.']);

        expect(DB::connection('pgsql_owner')->table('subscriptions')->count())->toBe(0)->and(($this->orgRow)()->status)->toBe('trial');
    });

    it('refuse un retour sans saisie du moyen de paiement ou une formule inconnue', function (): void {
        ($this->as)()->get('/facturation/retour?plan=basic&setup_ref=seti_inconnu')->assertSessionHasErrors('billing');
        ($this->as)()->post('/facturation/abonnement', ['plan' => 'platinum'])->assertNotFound();
        ($this->as)()->get('/facturation/retour?plan=basic')->assertSessionHasErrors('setup_ref');
    });

    it('affiche les erreurs dans la langue du compte', function (string $locale, string $message): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());

        ($this->subscribe)('basic', 'declined')->assertSessionHasErrors(['billing' => $message]);
    })->with([
        ['fr', 'Le paiement a été refusé. Vérifiez votre moyen de paiement ou essayez une autre carte.'],
        ['nl', 'De betaling is geweigerd. Controleer uw betaalmiddel of probeer een andere kaart.'],
        ['en', 'The payment was declined. Check your payment method or try another card.'],
    ]);

    it('ne propose la page de simulation qu\'avec le prestataire simulé', function (): void {
        ($this->as)()->get('/facturation/simulation?ref=seti_x')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Billing/Simulation'));

    });

    it('cache la page de simulation avec un vrai prestataire', function (): void {
        app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class));

        ($this->as)()->get('/facturation/simulation?ref=seti_x')->assertNotFound();
    });

    it('interdit de souscrire à un compte suspendu par la plateforme ou en suppression', function (string $status, ?string $reason): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status, 'suspension_reason' => $reason])->save());

        $response = ($this->as)()->post('/facturation/abonnement', ['plan' => 'basic']);

        $response->assertRedirect('/facturation')->assertSessionHasErrors('billing');
    })->with([['suspended', 'Fraude constatée'], ['pending_deletion', null], ['paused', null]]);
});

describe('formule, annulation, moyen de paiement', function (): void {
    beforeEach(function (): void {
        ($this->subscribe)('basic');
    });

    it('monte de formule avec un complément au prorata', function (): void {
        ($this->as)()->post('/facturation/abonnement', ['plan' => 'business'])->assertSessionHas('status', 'Formule changée. Le complément a été facturé au prorata.');

        expect(DB::connection('pgsql_owner')->table('subscriptions')->value('plan_id'))->toBe(Plan::query()->where('code', 'business')->value('id'))
            ->and(DB::connection('pgsql_owner')->table('invoices')->count())->toBe(2);
    });

    it('programme une descente et la refuse si l\'usage dépasse la formule', function (): void {
        ($this->as)()->post('/facturation/abonnement', ['plan' => 'business']);
        ($this->as)()->post('/facturation/abonnement', ['plan' => 'basic'])->assertSessionHas('status', 'Changement programmé pour la prochaine échéance.');
        ($this->as)()->get('/facturation')->assertInertia(fn (Assert $page) => $page->where('subscription.pending_plan', 'basic'));

        $this->asOwner(function (): void {
            foreach (['Léa', 'Marc'] as $name) {
                Practitioner::query()->create(['org_id' => $this->org->id, 'name' => $name, 'color' => '#2A5BD7']);
            }
        });
        ($this->as)()->post('/facturation/abonnement', ['plan' => 'business']);   // annule la descente
        ($this->as)()->post('/facturation/abonnement', ['plan' => 'basic'])->assertSessionHasErrors(['billing' => 'Impossible : vous avez plus de praticiens actifs que cette formule n\'en inclut (1).']);
    });

    it('annule puis reprend l\'abonnement', function (): void {
        ($this->as)()->post('/facturation/annulation')->assertSessionHas('status', 'Annulation enregistrée : l\'abonnement se termine à la fin de la période payée.');
        ($this->as)()->get('/facturation')->assertInertia(fn (Assert $page) => $page->whereNot('subscription.cancel_at', null));

        ($this->as)()->delete('/facturation/annulation')->assertSessionHas('status', 'Annulation retirée : l\'abonnement continue.');
        expect(DB::connection('pgsql_owner')->table('subscriptions')->value('cancel_at'))->toBeNull();
    });

    it('remplace le moyen de paiement par la page du prestataire', function (): void {
        $redirect = ($this->as)()->post('/facturation/moyen-de-paiement');
        $ref = explode('ref=', (string) $redirect->headers->get('Location'))[1];
        $return = ($this->as)()->post('/facturation/simulation', ['ref' => $ref, 'card' => 'declined']);

        ($this->as)()->get((string) $return->headers->get('Location'))->assertSessionHas('status', 'Moyen de paiement mis à jour.');

        expect(DB::connection('pgsql_owner')->table('payment_methods')->count())->toBe(1)->and(DB::connection('pgsql_owner')->table('payment_methods')->value('last4'))->toBe('0002');
    });
});

describe('identité de facturation', function (): void {
    it('enregistre l\'identité, normalise le numéro de TVA et ne trace que les champs modifiés', function (): void {
        ($this->as)()->put('/facturation/identite', ['billing_name' => ' Aurore SRL ', 'vat_number' => 'be 0123.456.789', 'line1' => 'Rue de la Loi 1', 'postal_code' => '1000', 'city' => 'Bruxelles'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Identité de facturation enregistrée.');

        $org = ($this->orgRow)();
        $audit = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'billing.identity_updated')->sole());

        expect($org)->billing_name->toBe('Aurore SRL')->billing_vat_number->toBe('BE0123456789')
            ->and(json_decode($org->billing_address, true))->toEqual(['line1' => 'Rue de la Loi 1', 'postal_code' => '1000', 'city' => 'Bruxelles'])
            ->and(json_decode($audit->diff, true))->toBe(['fields' => ['billing_name', 'billing_vat_number', 'billing_address']])
            ->and(json_encode($audit))->not->toContain('Aurore SRL')->not->toContain('0123456789');
    });

    it('refuse un numéro de TVA invalide', function (): void {
        ($this->as)()->put('/facturation/identite', ['vat_number' => '12345'])->assertSessionHasErrors('vat_number');
    });

    it('applique l\'identité aux factures suivantes seulement', function (): void {
        ($this->subscribe)('basic');
        ($this->as)()->put('/facturation/identite', ['billing_name' => 'Nouveau nom']);

        $invoice = app(TenantContext::class)->run($this->org->id, fn () => Invoice::query()->sole());

        expect($invoice->billing_snapshot['name'])->toBe('Salon Aurore');
    });
});

describe('factures', function (): void {
    it('affiche une facture avec ses lignes, ses totaux et les identités figées', function (): void {
        ($this->subscribe)('basic');
        $invoice = app(TenantContext::class)->run($this->org->id, fn () => Invoice::query()->sole());

        ($this->as)()->get("/facturation/factures/{$invoice->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Invoice')
            ->where('invoice.number', fn (string $number): bool => str_starts_with($number, 'NOVA-'.now()->format('Y').'-'))
            ->where('invoice.total_cents', 3629)->where('invoice.vat_rate', 21)->where('invoice.customer.name', 'Salon Aurore')
            ->has('invoice.lines', 1)->has('invoice.seller.name'));
    });

    it('répond 404 pour la facture d\'une autre organisation', function (): void {
        ($this->subscribe)('basic');
        $invoice = app(TenantContext::class)->run($this->org->id, fn () => Invoice::query()->sole());
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->get("/facturation/factures/{$invoice->id}")->assertNotFound();
        $this->signedIn($stranger, $otherOrg)->get('/facturation')->assertInertia(fn (Assert $page) => $page->has('invoices', 0)->where('subscription', null));
    });
});
