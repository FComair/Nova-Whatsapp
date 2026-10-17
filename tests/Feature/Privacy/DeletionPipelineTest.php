<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\DeletionRequestResource;
use App\Modules\Admin\Filament\Resources\DeletionRequestResource\Pages\ListDeletionRequests;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\OrganizationLifecycle;
use App\Modules\Api\Models\ApiClient;
use App\Modules\Api\Services\ApiClientService;
use App\Modules\Audit\Audit;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Calendars\Models\ExternalBusySlot;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Calendars\Services\CalendarFeedService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Privacy\Models\DeletionJob;
use App\Modules\Privacy\Models\DeletionRequest;
use App\Modules\Privacy\Notifications\DeletionCompletedNotification;
use App\Modules\Privacy\Notifications\DeletionFailedNotification;
use App\Modules\Privacy\Services\DeletionPipeline;
use App\Modules\Privacy\Support\ErasureSteps;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Pipeline RGPD : suppression réelle d'un compte à l'échéance, table par table, avec reprise, alerte et isolation.
 */
beforeEach(function (): void {
    Notification::fake();
    config(['rendivoo.founder_email' => 'fondateur@rendivoo.test']);

    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount(['name' => 'Salon Aurore']);
    $this->bookingWorld(existing: $org);

    // Un praticien qui appartient AUSSI à une autre organisation : son compte doit survivre.
    $this->otherOrg = $this->asOwner(fn () => Organization::factory()->create(['name' => 'Cabinet Voisin']));
    $this->shared = $this->asOwner(function (): User {
        $user = User::factory()->create(['email' => 'partage@example.com']);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'practitioner']);
        OrgMember::factory()->create(['org_id' => $this->otherOrg->id, 'user_id' => $user->id, 'role' => 'receptionist']);

        return $user;
    });
    $this->asOwner(function (): void {
        DB::table('password_reset_tokens')->insert(['email' => 'proprio@example.com', 'token' => 'hash', 'created_at' => now()]);
        Location::factory()->create(['org_id' => $this->otherOrg->id, 'is_default' => true]);
        Customer::query()->create(['org_id' => $this->otherOrg->id, 'phone_e164' => '+32470999000', 'name' => 'Client Voisin']);
    });

    $this->world = function (): void {
        $tenants = app(TenantContext::class);
        $tenants->run($this->org->id, function (): void {
            $booking = app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00'));
            WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token']);
            WhatsappMessage::query()->create(['org_id' => $this->org->id, 'customer_id' => $this->customer->id, 'direction' => 'in', 'wa_message_id' => 'w1', 'type' => 'text', 'body_enc' => 'Bonjour', 'status' => 'received']);
            $calendar = ExternalCalendar::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'name' => 'Google', 'kind' => 'ics', 'url_enc' => 'https://calendar.example.com/x.ics']);
            ExternalBusySlot::query()->create(['org_id' => $this->org->id, 'calendar_id' => $calendar->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
            app(CalendarFeedService::class)->tokenFor($this->org, $this->practitioner);
            app(ApiClientService::class)->issue($this->org, 'n8n', ApiClient::SCOPES);
            Audit::record('customer.updated', 'user', (string) $this->owner->id, $this->org->id, 'customers', $this->customer->id, ['fields' => ['name']]);
            expect($booking->id)->not->toBeEmpty();
        });
    };

    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->schedule = function (bool $due = true): PlatformOrganization {
        $platform = PlatformOrganization::query()->findOrFail($this->org->id);
        app(OrganizationLifecycle::class)->scheduleDeletion($platform, $this->admin->id, 'Demande écrite du propriétaire');
        DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->update(['deletion_scheduled_for' => $due ? now()->subMinute() : now()->addDays(10)]);

        return $platform->refresh();
    };
    $this->run = fn (): int => app(DeletionPipeline::class)->run();
    $owner = fn (string $table) => DB::connection('pgsql_owner')->table($table);
    $this->count = fn (string $table): int => $owner($table)->where('org_id', $this->org->id)->count();
    $this->orgRow = fn () => $owner('organizations')->where('id', $this->org->id)->first();
});

describe('suppression complète', function (): void {
    it('efface toutes les données du compte, table par table, et pseudonymise l\'organisation', function (): void {
        ($this->world)();
        ($this->schedule)();

        expect(($this->run)())->toBe(1);

        foreach (array_diff(ErasureSteps::ALL, ['users', 'audit_logs', 'invoices']) as $table) {
            expect(($this->count)($table))->toBe(0, "La table {$table} contient encore des données");
        }

        $org = DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->first();

        expect($org)->status->toBe('deleted')->name->toBe('Organisation supprimée')->slug->toBe('supprimee-'.$this->org->id)->billing_name->toBeNull()->previous_status->toBeNull()
            ->and($org->deleted_at)->not->toBeNull()
            ->and($org->sector)->not->toBeNull();   // secteur et pays restent : statistiques et obligations
    });

    it('supprime les comptes utilisateurs de l\'organisation, sauf ceux qui appartiennent aussi à une autre', function (): void {
        ($this->world)();
        ($this->schedule)();
        ($this->run)();

        $emails = DB::connection('pgsql_owner')->table('users')->pluck('email')->all();

        expect($emails)->not->toContain('proprio@example.com')->toContain('partage@example.com')
            ->and(DB::connection('pgsql_owner')->table('org_members')->where('user_id', $this->shared->id)->pluck('org_id')->all())->toBe([$this->otherOrg->id])
            ->and(DB::connection('pgsql_owner')->table('password_reset_tokens')->where('email', 'proprio@example.com')->count())->toBe(0);
    });

    it('ne touche à aucune donnée d\'une autre organisation', function (): void {
        ($this->world)();
        ($this->schedule)();
        ($this->run)();

        $other = fn (string $table) => DB::connection('pgsql_owner')->table($table)->where('org_id', $this->otherOrg->id)->count();

        expect($other('customers'))->toBe(1)->and($other('locations'))->toBe(1)->and($other('org_members'))->toBe(1)
            ->and(DB::connection('pgsql_owner')->table('organizations')->where('id', $this->otherOrg->id)->value('name'))->toBe('Cabinet Voisin');
    });

    it('garde comme preuve les actions du Super Admin et le suivi, mais pas les journaux du client', function (): void {
        ($this->world)();
        ($this->schedule)();
        ($this->run)();

        $actions = DB::connection('pgsql_owner')->table('audit_logs')->where('org_id', $this->org->id)->pluck('actor_type', 'action');

        expect($actions->keys()->all())->toContain('organization.deletion_scheduled', 'gdpr.deletion_started', 'organization.deleted')->not->toContain('customer.updated')
            ->and($actions->except(['gdpr.deletion_started', 'organization.deleted'])->every(fn (string $actor): bool => $actor === 'platform_admin'))->toBeTrue();
    });

    it('confirme au propriétaire puis oublie son adresse ; aucune donnée personnelle ne reste dans le suivi', function (): void {
        ($this->world)();
        ($this->schedule)();
        ($this->run)();

        Notification::assertSentOnDemand(DeletionCompletedNotification::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'proprio@example.com');

        $dump = json_encode([DB::connection('pgsql_owner')->table('deletion_requests')->get(), DB::connection('pgsql_owner')->table('deletion_jobs')->get()]);

        expect($dump)->not->toContain('proprio@')->not->toContain('Salon Aurore');
        expect(DeletionRequest::query()->findOrFail($this->org->id))->owner_email_enc->toBeNull()->org_name_enc->toBeNull()->completed_at->not->toBeNull();
    });

    it('enregistre le nombre de lignes effacées par étape', function (): void {
        ($this->world)();
        ($this->schedule)();
        ($this->run)();

        $jobs = DeletionJob::query()->where('org_id', $this->org->id)->pluck('rows_deleted', 'step');

        expect($jobs)->toHaveCount(count(ErasureSteps::ALL))->and($jobs['bookings'])->toBe(1)->and($jobs['whatsapp_messages'])->toBe(1)->and($jobs['customers'])->toBe(1)
            ->and(DeletionJob::query()->where('org_id', $this->org->id)->where('status', '!=', 'done')->count())->toBe(0);
    });

    it('est sans effet une fois terminé', function (): void {
        ($this->schedule)();

        expect(($this->run)())->toBe(1)->and(($this->run)())->toBe(0);
    });
});

describe('échéance', function (): void {
    it('n\'efface rien avant l\'échéance', function (): void {
        ($this->world)();
        ($this->schedule)(due: false);

        expect(($this->run)())->toBe(0)->and(($this->count)('customers'))->toBe(1)->and(($this->orgRow)()->status)->toBe('pending_deletion');
        expect(DeletionRequest::query()->count())->toBe(0);
    });

    it('ignore un compte qui n\'est pas en suppression', function (): void {
        ($this->world)();
        DB::connection('pgsql_owner')->table('organizations')->where('id', $this->org->id)->update(['deletion_scheduled_for' => now()->subDay()]);

        expect(($this->run)())->toBe(0)->and(($this->count)('customers'))->toBe(1);
    });

    it('ne supprime pas un compte dont la suppression a été annulée', function (): void {
        ($this->world)();
        $platform = ($this->schedule)();
        app(OrganizationLifecycle::class)->cancelDeletion($platform, $this->admin->id, 'Le propriétaire a changé d\'avis');

        expect(($this->run)())->toBe(0)->and(($this->count)('customers'))->toBe(1);
    });

    it('ne tourne pas deux fois en même temps', function (): void {
        ($this->schedule)();
        $other = DB::connection('pgsql_owner');
        $other->select('select pg_advisory_lock(?)', [7_300_113]);

        try {
            // Le verrou est pris par une AUTRE session : ce passage doit renoncer.
            expect(($this->run)())->toBe(0);
        } finally {
            $other->select('select pg_advisory_unlock(?)', [7_300_113]);
        }

        expect(($this->run)())->toBe(1);
    });
});

describe('suppression immédiate (fraude)', function (): void {
    it('ramène l\'échéance à maintenant et efface aussitôt', function (): void {
        ($this->world)();
        $platform = PlatformOrganization::query()->findOrFail($this->org->id);

        app(OrganizationLifecycle::class)->deleteNow($platform, $this->admin->id, 'Fraude avérée : faux comptes en série');

        expect(($this->run)())->toBe(1)->and(DeletionRequest::query()->findOrFail($this->org->id)->kind)->toBe('immediate')->and(($this->orgRow)()->status)->toBe('deleted');
        expect(DB::connection('pgsql_owner')->table('audit_logs')->where('action', 'organization.deletion_immediate')->count())->toBe(1);
    });

    it('accélère aussi une suppression standard déjà programmée', function (): void {
        ($this->schedule)(due: false);
        app(OrganizationLifecycle::class)->deleteNow(PlatformOrganization::query()->findOrFail($this->org->id), $this->admin->id, 'Abus constaté après programmation');

        expect(($this->run)())->toBe(1);
    });
});

describe('incident et reprise (E12)', function (): void {
    beforeEach(function (): void {
        // Les échecs sont provoqués exprès : on les intercepte (et on les vérifie) au lieu de polluer le journal.
        Log::spy();
        DB::connection('pgsql_owner')->unprepared("CREATE OR REPLACE FUNCTION test_block_customers() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'blocage de test avec donnée secrète Client Test'; END; \$\$");
        DB::connection('pgsql_owner')->unprepared('CREATE TRIGGER test_block_customers BEFORE DELETE ON customers FOR EACH ROW EXECUTE FUNCTION test_block_customers()');
    });

    afterEach(function (): void {
        DB::connection('pgsql_owner')->unprepared('DROP TRIGGER IF EXISTS test_block_customers ON customers');
        DB::connection('pgsql_owner')->unprepared('DROP FUNCTION IF EXISTS test_block_customers()');
    });

    it('réessaie 3 fois, alerte le fondateur une seule fois, puis attend une relance', function (): void {
        ($this->world)();
        ($this->schedule)();

        foreach (range(1, 5) as $ignored) {
            expect(($this->run)())->toBe(0);
        }

        $job = DeletionJob::query()->where('org_id', $this->org->id)->where('step', 'customers')->firstOrFail();

        expect($job)->status->toBe('failed')->attempts->toBe(3)->and($job->last_error)->toStartWith('sql_');
        Notification::assertSentOnDemandTimes(DeletionFailedNotification::class, 1);
        Notification::assertSentOnDemand(DeletionFailedNotification::class, fn ($n, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'fondateur@rendivoo.test');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Effacement RGPD en échec' && $context['step'] === 'customers' && $context['org'] === $this->org->id)->times(3);

        // Les étapes précédentes sont faites (réservations, conversations…), les suivantes attendent.
        expect(($this->count)('bookings'))->toBe(0)->and(($this->count)('customers'))->toBe(1)->and(($this->orgRow)()->status)->toBe('pending_deletion');
    });

    it('ne consigne jamais le message SQL (qui pourrait citer des données)', function (): void {
        ($this->world)();
        ($this->schedule)();
        ($this->run)();

        $dump = json_encode(DB::connection('pgsql_owner')->table('deletion_jobs')->get());

        expect($dump)->not->toContain('secrète')->not->toContain('Client Test');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $message === 'Effacement RGPD en échec' && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secrète'))->once();
    });

    it('reprend là où il s\'est arrêté après une relance, sans rien refaire', function (): void {
        ($this->world)();
        ($this->schedule)();
        foreach (range(1, 3) as $ignored) {
            ($this->run)();
        }
        $doneBefore = DeletionJob::query()->where('org_id', $this->org->id)->where('status', 'done')->pluck('completed_at', 'step');

        DB::connection('pgsql_owner')->unprepared('DROP TRIGGER test_block_customers ON customers');
        expect(app(DeletionPipeline::class)->retry(DeletionRequest::query()->findOrFail($this->org->id)))->toBe(1);

        expect(($this->run)())->toBe(1);
        expect(DeletionJob::query()->where('org_id', $this->org->id)->where('step', 'bookings')->firstOrFail()->completed_at->equalTo($doneBefore['bookings']))->toBeTrue();
        expect(($this->orgRow)()->status)->toBe('deleted');
    });
});

describe('garde-fous de la base', function (): void {
    it('couvre toute table portant org_id (une nouvelle table sans étape d\'effacement fait échouer ce test)', function (): void {
        $tables = DB::connection('pgsql_owner')->table('information_schema.columns')->where('table_schema', 'public')->where('column_name', 'org_id')->pluck('table_name')->all();
        $tracking = ['deletion_requests', 'deletion_jobs'];

        expect(array_values(array_diff($tables, $tracking, ErasureSteps::ALL, ErasureSteps::RETAINED)))->toBe([], 'Tables avec org_id sans étape d\'effacement')
            ->and(array_values(array_diff(array_diff(ErasureSteps::ALL, ['users']), $tables)))->toBe([], 'Étapes pour des tables inexistantes');
    });

    it('refuse une étape inconnue ou une tentative d\'injection', function (string $step): void {
        expect(fn () => DB::connection('pgsql_platform')->selectOne('select gdpr_purge_org(?, ?)', [$this->org->id, $step]))->toThrow(QueryException::class);
        ($this->world)();
        expect(($this->count)('customers'))->toBe(1);
    })->with(['platform_admins', 'organizations', 'customers; DROP TABLE customers', 'nope']);

    it('interdit au rôle applicatif d\'appeler la fonction ou d\'effacer les journaux, même avec le réglage de purge', function (): void {
        ($this->world)();

        expect(fn () => DB::connection('pgsql')->selectOne('select gdpr_purge_org(?, ?)', [$this->org->id, 'customers']))->toThrow(QueryException::class);

        app(TenantContext::class)->run($this->org->id, function (): void {
            DB::select("select set_config('nova.gdpr_purge', ?, false)", [$this->org->id]);

            expect(fn () => DB::table('booking_events')->delete())->toThrow(QueryException::class)
                ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class);
        });
    });

    it('interdit au rôle plateforme d\'effacer directement les tables des clients', function (): void {
        ($this->world)();

        foreach (['customers', 'bookings', 'booking_events', 'audit_logs', 'whatsapp_messages'] as $table) {
            expect(fn () => DB::connection('pgsql_platform')->table($table)->where('org_id', $this->org->id)->delete())->toThrow(QueryException::class);
        }
    });

    it('refuse l\'accès du rôle applicatif aux tables de suivi des suppressions', function (): void {
        expect(fn () => DB::connection('pgsql')->table('deletion_requests')->count())->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql')->table('deletion_jobs')->count())->toThrow(QueryException::class);
    });

    it('garde les tables en ajout seul protégées hors de la fonction d\'effacement', function (): void {
        ($this->world)();

        // Même le propriétaire de la base ne peut pas supprimer un événement sans passer par la fonction.
        expect(fn () => DB::connection('pgsql_owner')->table('booking_events')->where('org_id', $this->org->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_owner')->table('audit_logs')->where('org_id', $this->org->id)->update(['reason' => 'trafiqué']))->toThrow(QueryException::class);
    });
});

describe('Super Admin', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Cache::flush();
        $this->credentials = fn (array $overrides = []): array => [
            'reason' => 'Fraude avérée : faux comptes en série',
            'irreversible' => true,
            ...$overrides,
        ];
        $this->page = fn () => Livewire::actingAs($this->admin, 'platform')->test(ViewOrganization::class, ['record' => $this->org->id]);
    });

    it('programme la suppression immédiate avec la case « irréversible »', function (): void {
        ($this->page)()->callAction('delete_now', data: ($this->credentials)());

        $org = ($this->orgRow)();
        expect($org)->status->toBe('pending_deletion')->and(now()->diffInSeconds($org->deletion_scheduled_for, false))->toBeLessThan(5);
    });

    it('refuse sans la case « irréversible »', function (array $override): void {
        ($this->page)()->callAction('delete_now', data: ($this->credentials)($override));

        expect(($this->orgRow)()->status)->toBe('trial');
    })->with([
        'case décochée' => [['irreversible' => false]],
    ]);

    it('liste les suppressions, signale une étape bloquée et permet de la relancer avec un motif', function (): void {
        Log::spy();
        ($this->world)();
        ($this->schedule)();
        DB::connection('pgsql_owner')->unprepared("CREATE OR REPLACE FUNCTION test_block_customers() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'bloqué'; END; \$\$");
        DB::connection('pgsql_owner')->unprepared('CREATE TRIGGER test_block_customers BEFORE DELETE ON customers FOR EACH ROW EXECUTE FUNCTION test_block_customers()');

        try {
            foreach (range(1, 3) as $ignored) {
                ($this->run)();
            }

            $request = DeletionRequest::query()->findOrFail($this->org->id);
            expect(DeletionRequestResource::stateOf($request))->toBe('failed');

            $list = fn () => Livewire::actingAs($this->admin, 'platform')->test(ListDeletionRequests::class);
            $list()->assertCanSeeTableRecords([$request])->assertTableActionVisible('retry', $request);
            DB::connection('pgsql_owner')->unprepared('DROP TRIGGER test_block_customers ON customers');
            $list()->callTableAction('retry', $request, data: ['reason' => 'Correction appliquée en base']);

            expect(DeletionJob::query()->where('status', 'failed')->count())->toBe(0)->and(($this->run)())->toBe(1);
            expect(DeletionRequestResource::stateOf($request->refresh()))->toBe('completed');
            expect(DB::connection('pgsql_owner')->table('audit_logs')->where('action', 'gdpr.retry_requested')->count())->toBe(1);
        } finally {
            DB::connection('pgsql_owner')->unprepared('DROP TRIGGER IF EXISTS test_block_customers ON customers');
            DB::connection('pgsql_owner')->unprepared('DROP FUNCTION IF EXISTS test_block_customers()');
        }
    });
});
