<?php

declare(strict_types=1);

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformNotification;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Notifications\AgentTicketNotification;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Services\TicketMetrics;
use App\Modules\Identity\Models\User;
use App\Modules\Privacy\Support\ErasureSteps;
use App\Modules\Support\Exceptions\TicketChanged;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportTicketEvent;
use App\Modules\Support\Models\SupportTicketTag;
use App\Modules\Support\Notifications\TicketClosedNotification;
use App\Modules\Support\Notifications\TicketCreatedNotification;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Modules\Support\Notifications\TicketResolvedNotification;
use App\Modules\Support\Notifications\TicketStatusNotification;
use App\Modules\Support\Services\SupportSla;
use App\Modules\Support\Support\TicketStatus;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Notifications\Factory;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Espace de support du Super Admin (docs/29) : cycle de vie, assignation, notes internes, priorité, étiquettes, liens, aide interne,
 * droits (Super Admin, agent, client), journal d'audit, échéance de première réponse, chiffres, concurrence, isolation par la base.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    Storage::fake('local');
    Notification::fake();

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
    $this->other = PlatformAdmin::query()->create(['email' => 'agent2@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->other->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();

    $this->console = fn (): SupportConsole => app(SupportConsole::class);
    $this->open = function (array $overrides = []): PlatformSupportTicket {
        $this->signedIn($this->owner, $this->org)->post('/aide/contact', [
            'subject' => 'Les rappels ne partent pas', 'category' => 'technical', 'related_to' => 'whatsapp', 'urgency' => 'medium',
            'description' => 'Depuis hier, aucun rappel WhatsApp n\'est envoyé.', 'email' => 'contact@example.com', 'token' => (string) Str::uuid(), ...$overrides,
        ])->assertSessionHasNoErrors();

        return PlatformSupportTicket::query()->latest('created_at')->latest('reference_number')->firstOrFail();
    };
    $this->fresh = fn (PlatformSupportTicket $ticket): PlatformSupportTicket => PlatformSupportTicket::query()->findOrFail($ticket->id);
    $this->events = fn (PlatformSupportTicket $ticket, ?string $action = null) => SupportTicketEvent::on('pgsql_platform')->where('ticket_id', $ticket->id)->when($action, fn ($q) => $q->where('action', $action))->orderBy('created_at')->get();
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
});

describe('création et suivi du premier délai', function (): void {
    it('crée le ticket « Nouveau » avec son origine, son échéance de première réponse et prévient tous les agents', function (): void {
        $ticket = ($this->open)();

        expect($ticket->status)->toBe(TicketStatus::NEW)->and($ticket->source)->toBe('contact_form')->and($ticket->priority())->toBe('medium')
            ->and($ticket->first_response_at)->toBeNull()->and($ticket->assigned_admin_id)->toBeNull()
            ->and($ticket->last_client_message_at)->not->toBeNull()
            ->and($ticket->first_response_due_at->equalTo($ticket->created_at->copy()->addHours(app(SupportSla::class)->targetHours('medium'))))->toBeTrue();

        Notification::assertSentTo($this->boss, AgentTicketNotification::class, fn ($notification, array $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($this->agent, AgentTicketNotification::class);
        Notification::assertSentTo($this->other, AgentTicketNotification::class);
    });

    it('ne compte que la première réponse publique comme première réponse', function (): void {
        $ticket = ($this->open)();

        ($this->console)()->note($this->boss, $ticket, 'Note à moi-même.');
        expect(($this->fresh)($ticket)->first_response_at)->toBeNull();

        $this->travel(2)->hours();
        ($this->console)()->reply($this->boss, ($this->fresh)($ticket), 'Bonjour, nous regardons.');
        $first = ($this->fresh)($ticket)->first_response_at;
        expect($first)->not->toBeNull()->and(($this->fresh)($ticket)->status)->toBe(TicketStatus::IN_PROGRESS);

        $this->travel(1)->hours();
        ($this->console)()->reply($this->boss, ($this->fresh)($ticket), 'Autre message.');
        expect(($this->fresh)($ticket)->first_response_at->equalTo($first))->toBeTrue();
    });

    it('classe un ticket à risque puis en retard, et ne prévient qu\'une fois par état', function (): void {
        $ticket = ($this->open)();
        $sla = app(SupportSla::class);

        expect($sla->stateOf($ticket))->toBeNull();

        $this->travel(19)->hours(); // 75 % de 24 h = 18 h
        expect($sla->stateOf(($this->fresh)($ticket)))->toBe('at_risk');
        $this->artisan('support:watch-sla')->assertSuccessful();
        $this->artisan('support:watch-sla')->assertSuccessful();
        expect(($this->fresh)($ticket)->sla_alert)->toBe('at_risk');
        Notification::assertSentToTimes($this->boss, AgentTicketNotification::class, 2); // création + un seul signalement « à risque »

        $this->travel(6)->hours();
        $this->artisan('support:watch-sla')->assertSuccessful();
        expect($sla->stateOf(($this->fresh)($ticket)))->toBe('overdue')->and(($this->fresh)($ticket)->sla_alert)->toBe('overdue');
        Notification::assertSentToTimes($this->boss, AgentTicketNotification::class, 3);
        expect(($this->audit)('support.sla.overdue'))->toHaveCount(1);
    });

    it('recalcule l\'échéance quand la priorité change avant toute réponse', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->setPriority($this->boss, $ticket, 'urgent');

        $after = ($this->fresh)($ticket);
        expect($after->priority())->toBe('urgent')->and($after->first_response_due_at->equalTo($after->created_at->copy()->addHours(app(SupportSla::class)->targetHours('urgent'))))->toBeTrue();
    });
});

describe('assignation et propriété', function (): void {
    it('assigne, passe « Nouveau » à « Ouvert », prévient l\'assigné et laisse une trace interne', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);

        $after = ($this->fresh)($ticket);
        expect($after->assigned_admin_id)->toBe($this->agent->id)->and($after->status)->toBe(TicketStatus::OPEN);
        $event = ($this->events)($ticket, 'assigned')->first();
        expect($event->visibility)->toBe('internal')->and($event->meta)->toEqual(['from' => null, 'to' => $this->agent->id]);
        Notification::assertSentTo($this->agent, AgentTicketNotification::class, fn ($n, array $channels) => $channels === ['database', 'mail']);
        expect(($this->audit)('support.ticket.assigned'))->toHaveCount(1);
    });

    it('ne prévient pas quelqu\'un qui s\'assigne le ticket lui-même', function (): void {
        $ticket = ($this->open)();
        Notification::fake();

        ($this->console)()->assign($this->agent, $ticket, $this->agent->id);

        Notification::assertNothingSent();
    });

    it('réassigne et remet dans la file sans agent, chaque étape tracée', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);
        ($this->console)()->assign($this->boss, ($this->fresh)($ticket), $this->other->id);
        ($this->console)()->assign($this->boss, ($this->fresh)($ticket), null);

        expect(($this->fresh)($ticket)->assigned_admin_id)->toBeNull();
        expect(($this->events)($ticket)->pluck('action')->all())->toBe(['created', 'assigned', 'reassigned', 'unassigned']);
        expect(($this->audit)('support.ticket.assigned'))->toHaveCount(3);
    });

    it('refuse d\'assigner à un compte inconnu ou de modifier un ticket fermé', function (): void {
        $ticket = ($this->open)();

        expect(fn () => ($this->console)()->assign($this->boss, $ticket, (string) Str::uuid()))->toThrow(AuthorizationException::class);

        ($this->console)()->close($this->boss, $ticket);
        expect(fn () => ($this->console)()->assign($this->boss, ($this->fresh)($ticket), $this->agent->id))->toThrow(TicketRefused::class);
    });
});

describe('réponses, notes internes et états', function (): void {
    it('répond au client par e-mail avec ses pièces jointes, et lui montre la réponse', function (): void {
        $ticket = ($this->open)();
        $file = UploadedFile::fake()->image('capture.png', 40, 40);

        ($this->console)()->reply($this->boss, $ticket, 'Voici la procédure.', null, [$file]);

        Notification::assertSentOnDemand(TicketReplyNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'contact@example.com');
        $message = SupportMessage::on('pgsql_platform')->where('ticket_id', $ticket->id)->where('author_type', 'staff')->firstOrFail();
        expect($message->visibility)->toBe('public')->and($message->attachments()->count())->toBe(1)->and($message->author_id)->toBe($this->boss->id);
        expect(($this->audit)('support.ticket.replied'))->toHaveCount(1);
    });

    it('garde les notes internes invisibles du client, y compris leurs pièces jointes et les événements internes', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->note($this->boss, $ticket, 'Client pressé, à traiter en priorité.', [UploadedFile::fake()->image('interne.png', 40, 40)]);
        ($this->console)()->assign($this->boss, ($this->fresh)($ticket), $this->agent->id);
        ($this->console)()->setPriority($this->boss, ($this->fresh)($ticket), 'high');

        $seen = app(TenantContext::class)->run($this->org->id, fn () => [
            'messages' => SupportMessage::query()->where('ticket_id', $ticket->id)->pluck('visibility')->unique()->all(),
            'attachments' => DB::table('support_attachments')->count(),
            'events' => SupportTicketEvent::query()->where('ticket_id', $ticket->id)->pluck('action')->all(),
        ]);

        expect($seen['messages'])->toBe(['public'])->and($seen['attachments'])->toBe(0)->and($seen['events'])->toBe(['created']);
    });

    it('ne montre jamais au client les étiquettes, les liens ni les tables de l\'équipe', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->addTag($this->boss, $ticket, 'Remboursement');
        ($this->console)()->link($this->boss, $ticket, 'booking', 'RDV du 12/10');

        foreach (['support_ticket_tags', 'support_ticket_links', 'support_saved_views', 'platform_notifications'] as $table) {
            expect(fn () => app(TenantContext::class)->run($this->org->id, fn () => DB::table($table)->count()))->toThrow(QueryException::class);
        }

        expect(SupportTicketTag::query()->where('ticket_id', $ticket->id)->pluck('tag')->all())->toBe(['remboursement']);
    });

    it('prévient le client quand l\'état qu\'il voit change, jamais pour un changement interne', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->setStatus($this->boss, $ticket, TicketStatus::IN_PROGRESS);
        Notification::assertSentOnDemandTimes(TicketStatusNotification::class, 1);

        ($this->console)()->setStatus($this->boss, ($this->fresh)($ticket), TicketStatus::WAITING_INTERNAL);
        Notification::assertSentOnDemandTimes(TicketStatusNotification::class, 1); // « En cours » → « En cours »
        expect(($this->events)($ticket, 'status_changed')->pluck('visibility')->all())->toBe(['public', 'internal']);

        ($this->console)()->setStatus($this->boss, ($this->fresh)($ticket), TicketStatus::WAITING_ON_YOU);
        Notification::assertSentOnDemandTimes(TicketStatusNotification::class, 2);
    });

    it('résout avec e-mail, puis le client qui répond rouvre le ticket', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->setStatus($this->boss, $ticket, TicketStatus::RESOLVED);

        Notification::assertSentOnDemand(TicketResolvedNotification::class);
        expect(($this->fresh)($ticket))->status->toBe(TicketStatus::RESOLVED)->resolved_at->not->toBeNull();

        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => 'Cela ne marche toujours pas.'])->assertSessionHasNoErrors();
        expect(($this->fresh)($ticket))->status->toBe(TicketStatus::REOPENED)->resolved_at->toBeNull();
        expect(($this->events)($ticket, 'reopened'))->toHaveCount(1);
    });

    it('ferme avec e-mail ; le client ne peut plus répondre mais l\'équipe peut rouvrir', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->close($this->boss, $ticket);

        Notification::assertSentOnDemand(TicketClosedNotification::class);
        $after = ($this->fresh)($ticket);
        expect($after->status)->toBe(TicketStatus::CLOSED)->and($after->closed_reason)->toBe('staff')->and($after->closed_at)->not->toBeNull();
        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => 'Encore une question.'])->assertSessionHasErrors('body');

        expect(fn () => ($this->console)()->reply($this->boss, $after, 'Texte'))->toThrow(TicketRefused::class);
        expect(fn () => ($this->console)()->close($this->boss, $after))->toThrow(TicketRefused::class);

        ($this->console)()->reopen($this->boss, $after);
        $reopened = ($this->fresh)($ticket);
        expect($reopened->status)->toBe(TicketStatus::REOPENED)->and($reopened->closed_at)->toBeNull()->and($reopened->closed_reason)->toBeNull();
        Notification::assertSentOnDemand(TicketStatusNotification::class);
    });

    it('n\'accepte pas de rouvrir un ticket actif ni de fixer l\'état « fermé » par le changement d\'état', function (): void {
        $ticket = ($this->open)();

        expect(fn () => ($this->console)()->reopen($this->boss, $ticket))->toThrow(TicketRefused::class);
        expect(fn () => ($this->console)()->setStatus($this->boss, $ticket, TicketStatus::CLOSED))->toThrow(TicketRefused::class);
        expect(fn () => ($this->console)()->setStatus($this->boss, $ticket, 'inconnu'))->toThrow(TicketRefused::class);
    });

    it('demande l\'aide d\'une équipe interne sans rien envoyer au client', function (): void {
        $ticket = ($this->open)();
        Notification::fake();

        ($this->console)()->requestHelp($this->boss, $ticket, 'billing', 'Le client conteste une facture.');

        $after = ($this->fresh)($ticket);
        expect($after->status)->toBe(TicketStatus::WAITING_INTERNAL);
        $note = SupportMessage::on('pgsql_platform')->where('ticket_id', $ticket->id)->where('visibility', 'internal')->firstOrFail();
        expect($note->body)->toContain('Le client conteste une facture.');
        Notification::assertNothingSent();
        expect(($this->events)($ticket, 'help_requested')->first())->visibility->toBe('internal')->meta->toBe(['team' => 'billing']);
        expect(fn () => ($this->console)()->requestHelp($this->boss, $after, 'inconnue', 'x'))->toThrow(TicketRefused::class);
    });
});

describe('étiquettes et liens', function (): void {
    it('normalise, déduplique et retire les étiquettes', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->addTag($this->boss, $ticket, '  Facturation Urgente ');
        ($this->console)()->addTag($this->boss, $ticket, 'facturation-urgente');
        ($this->console)()->addTag($this->boss, $ticket, 'vip');

        expect(SupportTicketTag::query()->where('ticket_id', $ticket->id)->orderBy('tag')->pluck('tag')->all())->toBe(['facturation-urgente', 'vip']);
        expect(fn () => ($this->console)()->addTag($this->boss, $ticket, '   '))->toThrow(TicketRefused::class);

        ($this->console)()->removeTag($this->boss, $ticket, 'VIP');
        expect(SupportTicketTag::query()->where('ticket_id', $ticket->id)->pluck('tag')->all())->toBe(['facturation-urgente']);
        expect(($this->events)($ticket)->pluck('action')->all())->toBe(['created', 'tag_added', 'tag_added', 'tag_removed']);
    });

    it('relie un ticket à un autre ticket ou à un enregistrement, sans se relier à lui-même', function (): void {
        $a = ($this->open)();
        $b = ($this->open)(['subject' => 'Autre sujet']);

        ($this->console)()->link($this->boss, $a, 'ticket', $b->reference().' · '.$b->subject, $b->id);
        ($this->console)()->link($this->boss, $a, 'customer', 'Camille Client');
        ($this->console)()->link($this->boss, $a, 'customer', 'Camille Client');

        expect($a->links()->count())->toBe(2);
        expect(fn () => ($this->console)()->link($this->boss, $a, 'ticket', 'moi-même', $a->id))->toThrow(TicketRefused::class);
        expect(fn () => ($this->console)()->link($this->boss, $a, 'inconnu', 'x'))->toThrow(TicketRefused::class);

        $link = $a->links()->where('kind', 'customer')->firstOrFail();
        ($this->console)()->unlink($this->boss, $a, $link->id);
        expect($a->links()->count())->toBe(1);
    });
});

describe('concurrence', function (): void {
    it('refuse une modification faite sur un écran périmé et accepte la même après actualisation', function (): void {
        $ticket = ($this->open)();
        $seen = $ticket->version;

        ($this->console)()->assign($this->other, $ticket, $this->other->id, $seen);
        expect(fn () => ($this->console)()->setPriority($this->boss, $ticket, 'high', $seen))->toThrow(TicketChanged::class);
        expect(($this->fresh)($ticket)->priority())->toBe('medium');

        ($this->console)()->setPriority($this->boss, $ticket, 'high', ($this->fresh)($ticket)->version);
        expect(($this->fresh)($ticket)->priority())->toBe('high');
    });

    it('fait évoluer la version à chaque changement, du client comme de l\'équipe', function (): void {
        $ticket = ($this->open)();
        $v1 = ($this->fresh)($ticket)->version;

        $this->travel(2)->seconds();
        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => 'Une précision.'])->assertSessionHasNoErrors();
        $v2 = ($this->fresh)($ticket)->version;
        $this->travel(2)->seconds();
        ($this->console)()->reply($this->boss, ($this->fresh)($ticket), 'Merci.');

        expect($v2)->toBeGreaterThan($v1)->and(($this->fresh)($ticket)->version)->toBeGreaterThan($v2);
    });
});

describe('droits', function (): void {
    it('limite un agent à ses tickets et aux tickets non assignés', function (): void {
        $free = ($this->open)(['subject' => 'Libre']);
        $mine = ($this->open)(['subject' => 'Le mien']);
        $theirs = ($this->open)(['subject' => 'Celui d\'un collègue']);
        ($this->console)()->assign($this->boss, $mine, $this->agent->id);
        ($this->console)()->assign($this->boss, $theirs, $this->other->id);

        expect(($this->console)()->find($this->agent, $free->id)->id)->toBe($free->id)
            ->and(($this->console)()->find($this->agent, $mine->id)->id)->toBe($mine->id);
        expect(fn () => ($this->console)()->find($this->agent, $theirs->id))->toThrow(AuthorizationException::class);
        expect(fn () => ($this->console)()->reply($this->agent, $theirs, 'Je me permets…'))->toThrow(AuthorizationException::class);
        expect(fn () => ($this->console)()->note($this->agent, $theirs, 'Je me permets…'))->toThrow(AuthorizationException::class);
        expect(($this->console)()->find($this->boss, $theirs->id)->id)->toBe($theirs->id);
    });

    it('traite en lot seulement ce que l\'agent peut voir et compte le reste comme ignoré', function (): void {
        $mine = ($this->open)(['subject' => 'Le mien']);
        $theirs = ($this->open)(['subject' => 'Celui d\'un collègue']);
        ($this->console)()->assign($this->boss, $mine, $this->agent->id);
        ($this->console)()->assign($this->boss, $theirs, $this->other->id);

        $result = ($this->console)()->bulk($this->agent, [$mine->id, $theirs->id], 'priority', fn (PlatformSupportTicket $ticket) => ($this->console)()->setPriority($this->agent, $ticket, 'high'));

        expect($result)->toBe(['done' => 1, 'skipped' => 1]);
        expect(($this->fresh)($mine)->priority())->toBe('high')->and(($this->fresh)($theirs)->priority())->toBe('medium');
        expect(($this->audit)('support.tickets.bulk'))->toHaveCount(1);
    });

    it('trace chaque consultation et chaque action dans le journal d\'audit, avec le compte client concerné', function (): void {
        $ticket = ($this->open)();

        ($this->console)()->open($this->boss, $ticket->id);
        ($this->console)()->reply($this->boss, $ticket, 'Bonjour.');
        ($this->console)()->note($this->boss, ($this->fresh)($ticket), 'Note.');
        ($this->console)()->setPriority($this->boss, ($this->fresh)($ticket), 'high');
        ($this->console)()->addTag($this->boss, ($this->fresh)($ticket), 'vip');

        foreach (['viewed', 'replied', 'note_added', 'priority_changed', 'tag_added'] as $action) {
            $rows = ($this->audit)("support.ticket.{$action}");
            expect($rows)->toHaveCount(1)->and($rows->first()->org_id)->toBe($this->org->id)->and($rows->first()->entity_id)->toBe($ticket->id)->and($rows->first()->actor_id)->toBe($this->boss->id);
        }
    });

    it('ne laisse pas le rôle client écrire dans les champs ou tables de l\'équipe', function (): void {
        $ticket = ($this->open)();
        $asApp = fn (callable $run) => fn () => app(TenantContext::class)->run($this->org->id, $run);

        expect($asApp(fn () => DB::table('support_tickets')->where('id', $ticket->id)->update(['first_response_at' => now()])))->toThrow(QueryException::class);
        expect($asApp(fn () => DB::table('support_tickets')->where('id', $ticket->id)->update(['staff_priority' => 'urgent'])))->toThrow(QueryException::class);
        expect($asApp(fn () => DB::table('support_ticket_events')->insert(['org_id' => $this->org->id, 'ticket_id' => $ticket->id, 'actor_type' => 'client', 'action' => 'assigned', 'visibility' => 'internal'])))->toThrow(QueryException::class);
        expect($asApp(fn () => DB::table('support_ticket_tags')->insert(['org_id' => $this->org->id, 'ticket_id' => $ticket->id, 'tag' => 'x'])))->toThrow(QueryException::class);
    });

    it('isole les tickets d\'un compte client de ceux d\'un autre', function (): void {
        $ticket = ($this->open)();
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount([], 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->get("/aide/tickets/{$ticket->reference()}")->assertNotFound();
        $count = app(TenantContext::class)->run($otherOrg->id, fn () => DB::table('support_tickets')->count());
        expect($count)->toBe(0);
    });
});

describe('création manuelle', function (): void {
    it('ouvre un ticket au nom d\'un membre du compte, visible par lui, avec la trace de l\'équipe', function (): void {
        $data = ['org_id' => $this->org->id, 'user_id' => $this->owner->id, 'subject' => 'Appel du gérant', 'category' => 'billing', 'priority' => 'high', 'description' => 'Facture en double signalée par téléphone.', 'source' => 'manual', 'assigned_admin_id' => $this->agent->id];
        $ticket = ($this->console)()->create($this->boss, $data);

        expect($ticket->status)->toBe(TicketStatus::OPEN)->and($ticket->source)->toBe('manual')->and($ticket->reply_email)->toBe(mb_strtolower($this->owner->email))
            ->and($ticket->priority())->toBe('high')->and($ticket->assigned_admin_id)->toBe($this->agent->id);
        expect(($this->events)($ticket, 'created')->first())->actor_type->toBe('staff')->actor_id->toBe($this->boss->id);
        expect(($this->audit)('support.ticket.created'))->toHaveCount(1);
        Notification::assertSentOnDemand(TicketCreatedNotification::class);

        $this->signedIn($this->owner, $this->org)->get("/aide/tickets/{$ticket->reference()}")->assertOk();
    });

    it('refuse un demandeur qui n\'est pas membre du compte', function (): void {
        $stranger = $this->asOwner(fn () => User::factory()->create(['email' => 'etranger@example.com']));

        expect(fn () => ($this->console)()->create($this->boss, ['org_id' => $this->org->id, 'user_id' => $stranger->id, 'subject' => 'X', 'category' => 'other', 'priority' => 'low', 'description' => 'Y']))->toThrow(AuthorizationException::class);
        expect(collect(($this->console)()->members($this->org->id))->pluck('email')->all())->toBe([$this->owner->email]);
    });
});

describe('notifications des agents', function (): void {
    it('écrit dans la cloche du panel (table réservée à la plateforme) et respecte les préférences', function (): void {
        // Vrais envois : on retire la simulation posée par beforeEach.
        Notification::clearResolvedInstances();
        app()->forgetInstance(ChannelManager::class);
        app()->forgetInstance(Dispatcher::class);
        app()->forgetInstance(Factory::class);
        $this->boss->forceFill(['support_notifications' => ['new_ticket' => ['database'], 'client_reply' => []]])->save();
        $this->agent->forceFill(['support_notifications' => ['new_ticket' => []]])->save();

        $ticket = ($this->open)();

        $bell = PlatformNotification::query()->where('notifiable_id', $this->boss->id)->get();
        expect($bell)->toHaveCount(1)->and($bell->first()->data['format'])->toBe('filament')->and($bell->first()->data['title'])->toContain($ticket->reference());
        expect(PlatformNotification::query()->where('notifiable_id', $this->agent->id)->count())->toBe(0);

        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => 'Une précision.'])->assertSessionHasNoErrors();
        expect(PlatformNotification::query()->where('notifiable_id', $this->boss->id)->count())->toBe(1); // « réponse du client » désactivée
        expect(PlatformNotification::query()->where('notifiable_id', $this->other->id)->count())->toBe(2);
    });

    it('prévient seulement l\'assigné d\'une réponse du client, et personne quand l\'auteur est l\'assigné', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);
        Notification::fake();

        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => 'Une précision.'])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->agent, AgentTicketNotification::class);
        Notification::assertNotSentTo($this->boss, AgentTicketNotification::class);
        Notification::assertNotSentTo($this->other, AgentTicketNotification::class);

        Notification::fake();
        ($this->console)()->setPriority($this->boss, ($this->fresh)($ticket), 'urgent');
        Notification::assertSentTo($this->agent, AgentTicketNotification::class);
    });

    it('prévient l\'assigné quand le client ferme ou confirme la résolution', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);
        Notification::fake();

        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$ticket->reference()}/fermer")->assertSessionHasNoErrors();

        Notification::assertSentTo($this->agent, AgentTicketNotification::class);
    });
});

describe('chiffres du support', function (): void {
    it('calcule les indicateurs avec leurs définitions et les filtres', function (): void {
        $a = ($this->open)(['subject' => 'Ticket A']);
        $b = ($this->open)(['subject' => 'Ticket B', 'category' => 'billing']);
        ($this->open)(['subject' => 'Ticket C']);
        ($this->console)()->assign($this->boss, $a, $this->agent->id);
        $this->travel(3)->hours();
        ($this->console)()->reply($this->agent, ($this->fresh)($a), 'Bonjour.');
        ($this->console)()->setStatus($this->agent, ($this->fresh)($a), TicketStatus::RESOLVED);
        ($this->console)()->setStatus($this->boss, ($this->fresh)($b), TicketStatus::WAITING_ON_YOU);
        $this->signedIn($this->owner, $this->org)->post("/aide/tickets/{$a->reference()}/reponse", ['body' => 'Toujours pas.'])->assertSessionHasNoErrors();

        $metrics = app(TicketMetrics::class);
        $all = $metrics->compute($this->boss, []);

        expect($all['created'])->toBe(3)->and($all['open'])->toBe(3)->and($all['unassigned'])->toBe(2)->and($all['waiting_on_client'])->toBe(1)
            ->and($all['by_status'])->toMatchArray(['new' => 1, 'waiting_on_you' => 1, 'reopened' => 1])
            ->and($all['by_category'])->toMatchArray(['technical' => 2, 'billing' => 1])
            ->and($all['first_response_hours'])->toBe(3.0)->and($all['first_response_on_time'])->toBe(100.0)
            ->and($all['reopened'])->toBe(1)->and($all['overdue'])->toBe(0)
            ->and(collect($all['by_agent'])->firstWhere('agent', $this->agent->id))->toMatchArray(['total' => 1, 'open' => 1]);

        expect($metrics->compute($this->boss, ['category' => 'billing'])['created'])->toBe(1);
        expect($metrics->compute($this->boss, ['agent' => 'none'])['created'])->toBe(2);
        expect($metrics->compute($this->boss, ['agent' => $this->agent->id])['created'])->toBe(1);
        expect($metrics->compute($this->boss, ['priority' => 'urgent'])['created'])->toBe(0);
        expect($metrics->compute($this->boss, ['organization' => (string) Str::uuid()])['created'])->toBe(0);
        expect($metrics->compute($this->boss, ['from' => now()->addDay()->toDateString(), 'until' => now()->addDays(2)->toDateString()])['created'])->toBe(0);

        // Résolu puis rouvert par le client : n'est plus compté comme résolu aujourd'hui.
        expect($all['resolved_today'])->toBe(0);
        $d = ($this->open)(['subject' => 'Ticket D']);
        ($this->console)()->setStatus($this->boss, $d, TicketStatus::RESOLVED);
        expect($metrics->compute($this->boss, [])['resolved_today'])->toBe(1)->and($metrics->compute($this->boss, [])['resolved_week'])->toBe(1)->and($metrics->compute($this->boss, [])['resolved_month'])->toBe(1);
    });

    it('compte les tickets en retard et à risque, et limite un agent à sa portée', function (): void {
        $mine = ($this->open)(['subject' => 'Le mien']);
        $theirs = ($this->open)(['subject' => 'Celui d\'un collègue']);
        ($this->console)()->assign($this->boss, $mine, $this->agent->id);
        ($this->console)()->assign($this->boss, $theirs, $this->other->id);
        $metrics = app(TicketMetrics::class);

        $this->travel(19)->hours();
        expect($metrics->compute($this->boss, [])['at_risk'])->toBe(2)->and($metrics->compute($this->boss, [])['overdue'])->toBe(0);
        $this->travel(6)->hours();
        expect($metrics->compute($this->boss, [])['overdue'])->toBe(2);

        $scoped = $metrics->compute($this->agent, []);
        expect($scoped['created'])->toBe(1)->and($scoped['overdue'])->toBe(1)->and($scoped['open'])->toBe(1);
    });
});

describe('modèle de données', function (): void {
    it('refuse les valeurs hors liste et laisse le journal en ajout seul', function (): void {
        $ticket = ($this->open)();

        expect(fn () => PlatformSupportTicket::query()->whereKey($ticket->id)->update(['status' => 'inconnu']))->toThrow(QueryException::class);
        expect(fn () => PlatformSupportTicket::query()->whereKey($ticket->id)->update(['staff_priority' => 'critique']))->toThrow(QueryException::class);
        expect(fn () => PlatformSupportTicket::query()->whereKey($ticket->id)->update(['source' => 'pigeon']))->toThrow(QueryException::class);
        expect(fn () => PlatformSupportTicket::query()->whereKey($ticket->id)->update(['sla_alert' => 'critical']))->toThrow(QueryException::class);
        expect(fn () => SupportTicketEvent::on('pgsql_platform')->where('ticket_id', $ticket->id)->update(['note' => 'réécrit']))->toThrow(QueryException::class);
    });

    it('efface étiquettes et liens avec le compte client (RGPD)', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->addTag($this->boss, $ticket, 'vip');
        ($this->console)()->link($this->boss, $ticket, 'customer', 'Camille');

        expect(ErasureSteps::ALL)->toContain('support_ticket_tags', 'support_ticket_links');
        $this->asOwner(fn () => DB::select('select gdpr_purge_org(?, ?)', [$this->org->id, 'support_ticket_tags']));
        expect(SupportTicketTag::query()->where('ticket_id', $ticket->id)->count())->toBe(0);
    });
});
