<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Pages\SupportReports;
use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages\ListSupportTickets;
use App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Support\Models\SupportAttachment;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Modules\Support\Support\TicketStatus;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Super Admin > Tickets (docs/29) : boîte de réception (vues, recherche, filtres, actions en lot, export, vues enregistrées),
 * détail d'un ticket (réponse, note interne, actions, concurrence), rapports, accès des agents, pièces jointes.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    Storage::fake('local');
    Notification::fake();

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
    $this->colleague = PlatformAdmin::query()->create(['email' => 'collegue@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->colleague->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();

    foreach (['boss', 'agent', 'colleague'] as $name) {
        $this->{$name}->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->{$name} = $this->{$name}->refresh();
    }

    $this->as = fn (PlatformAdmin $admin) => $this->flushSession()->actingAs($admin, 'platform');
    $this->console = fn (): SupportConsole => app(SupportConsole::class);
    $this->open = function (array $overrides = []): PlatformSupportTicket {
        $this->signedIn($this->owner, $this->org)->post('/aide/contact', [
            'subject' => 'Les rappels ne partent pas', 'category' => 'technical', 'related_to' => 'whatsapp', 'urgency' => 'medium',
            'description' => 'Depuis hier, aucun rappel WhatsApp n\'est envoyé.', 'email' => 'contact@example.com', 'token' => (string) Str::uuid(), ...$overrides,
        ])->assertSessionHasNoErrors();

        return PlatformSupportTicket::query()->latest('created_at')->latest('reference_number')->firstOrFail();
    };
    $this->fresh = fn (PlatformSupportTicket $ticket): PlatformSupportTicket => PlatformSupportTicket::query()->findOrFail($ticket->id);
    $this->list = fn (?PlatformAdmin $admin = null) => Livewire::actingAs($admin ?? $this->boss, 'platform')->test(ListSupportTickets::class);
    $this->page = fn (PlatformSupportTicket $ticket, ?PlatformAdmin $admin = null) => Livewire::actingAs($admin ?? $this->boss, 'platform')->test(ViewSupportTicket::class, ['record' => $ticket->id]);
    $this->records = function (array $tickets) {
        $ids = collect($tickets)->pluck('id')->all();

        return PlatformSupportTicket::query()->whereIn('id', $ids)->get()->sortBy(fn (PlatformSupportTicket $ticket) => array_search($ticket->id, $ids, true))->values();
    };
});

describe('accès', function (): void {
    it('réserve les écrans du support aux comptes de la plateforme', function (): void {
        $this->get('/admin/support-tickets')->assertRedirect('/admin/login');
        $this->actingAs($this->owner, 'web')->get('/admin/support-tickets')->assertRedirect('/admin/login');
        ($this->as)($this->boss)->get('/admin/support-tickets')->assertOk();
        ($this->as)($this->boss)->get('/admin/support-reports')->assertOk();
    });

    it('ferme à un agent tout ce qui n\'est pas le support, et l\'envoie directement sur les tickets', function (): void {
        ($this->as)($this->agent)->get('/admin/support-tickets')->assertOk();
        ($this->as)($this->agent)->get('/admin/organizations')->assertForbidden();
        ($this->as)($this->agent)->get('/admin/invoices')->assertForbidden();
        ($this->as)($this->agent)->get('/admin/platform-settings')->assertForbidden();
        ($this->as)($this->agent)->get('/admin/audit-logs')->assertForbidden();
        ($this->as)($this->agent)->get('/admin')->assertRedirect(SupportTicketResource::getUrl());
        ($this->as)($this->boss)->get('/admin/organizations')->assertOk();
    });

    it('crée un agent de support avec --support-agent', function (): void {
        $this->artisan('rendivoo:create-admin', ['email' => 'nouvel.agent@rendivoo.test', '--support-agent' => true])
            ->expectsQuestion('Mot de passe (16 caractères minimum)', 'Un-Mot-De-Passe-Solide-2026!')
            ->expectsQuestion('Confirmez le mot de passe', 'Un-Mot-De-Passe-Solide-2026!')
            ->assertSuccessful();

        expect(PlatformAdmin::query()->where('email', 'nouvel.agent@rendivoo.test')->first())->support_role->toBe('agent');
    });
});

describe('boîte de réception', function (): void {
    it('liste les tickets avec leur compte client, leur état et leur priorité, et signale ceux qui attendent une réponse', function (): void {
        $a = ($this->open)(['subject' => 'Rappels en panne']);

        ($this->list)()->assertSuccessful()->assertCanSeeTableRecords(($this->records)([$a]))
            ->assertTableColumnStateSet('organization_name', $this->org->name, ($this->records)([$a])->first())
            ->assertTableColumnStateSet('attention', 'needs_response', ($this->records)([$a])->first())
            ->assertSee('NWS-'.$a->reference_number);
    });

    it('propose les vues prêtes à l\'emploi avec leur compteur', function (): void {
        $mine = ($this->open)(['subject' => 'Le mien en cours']);
        $free = ($this->open)(['subject' => 'Sans agent']);
        $waiting = ($this->open)(['subject' => 'Attend le client']);
        $urgent = ($this->open)(['subject' => 'Très urgent']);
        $done = ($this->open)(['subject' => 'Déjà résolu']);
        ($this->console)()->assign($this->boss, $mine, $this->boss->id);
        ($this->console)()->setStatus($this->boss, $waiting, TicketStatus::WAITING_ON_YOU);
        ($this->console)()->setPriority($this->boss, $urgent, 'urgent');
        ($this->console)()->setStatus($this->boss, $done, TicketStatus::RESOLVED);

        $sees = fn (string $tab, array $see, array $hide) => ($this->list)()->set('activeTab', $tab)->assertCanSeeTableRecords(($this->records)($see))->assertCanNotSeeTableRecords(($this->records)($hide));

        $sees('mine', [$mine], [$free, $waiting, $urgent, $done]);
        $sees('unassigned', [$free, $waiting, $urgent], [$mine, $done]);
        $sees('urgent', [$urgent], [$free, $mine]);
        $sees('waiting_client', [$waiting], [$free, $mine, $urgent]);
        $sees('resolved_today', [$done], [$free, $mine, $waiting]);
        $sees('all', [$mine, $free, $waiting, $urgent], [$done]);
        $sees('everything', [$mine, $free, $waiting, $urgent, $done], []);

        $tabs = ($this->list)()->instance()->getTabs();
        expect((int) $tabs['all']->getBadge())->toBe(4)->and((int) $tabs['unassigned']->getBadge())->toBe(3)->and((int) $tabs['urgent']->getBadge())->toBe(1);
    });

    it('met en « première réponse due » les tickets en retard ou à risque', function (): void {
        $late = ($this->open)(['subject' => 'Ancien ticket']);
        $this->travel(26)->hours();
        $fresh = ($this->open)(['subject' => 'Ticket tout neuf']);

        ($this->list)()->set('activeTab', 'due_first_response')->assertCanSeeTableRecords(($this->records)([$late]))->assertCanNotSeeTableRecords(($this->records)([$fresh]))
            ->assertTableColumnStateSet('attention', 'overdue', ($this->records)([$late])->first());
    });

    it('cherche par numéro, objet, compte client, demandeur, étiquette ou texte d\'un message', function (): void {
        $a = ($this->open)(['subject' => 'Facture en double', 'description' => 'La facture de mars apparaît deux fois.']);
        $b = ($this->open)(['subject' => 'Rappels en panne', 'email' => 'autre@example.com']);
        ($this->console)()->addTag($this->boss, $b, 'whatsapp-meta');
        ($this->console)()->note($this->boss, $b, 'Piste : jeton expiré côté Meta.');

        $search = fn (string $term, array $see, array $hide) => ($this->list)()->set('activeTab', 'everything')->searchTable($term)->assertCanSeeTableRecords(($this->records)($see))->assertCanNotSeeTableRecords(($this->records)($hide));

        $search('NWS-'.$a->reference_number, [$a], [$b]);
        $search((string) $b->reference_number, [$b], [$a]);
        $search('double', [$a], [$b]);
        $search('apparaît deux fois', [$a], [$b]);
        $search('autre@example', [$b], [$a]);
        $search('whatsapp-meta', [$b], [$a]);
        $search('jeton expiré', [$b], [$a]); // les notes internes se cherchent aussi
        $search($this->org->name, [$a, $b], []);
    });

    it('filtre par état, priorité, catégorie, assigné, origine, date et attention', function (): void {
        $a = ($this->open)(['subject' => 'Facturation A', 'category' => 'billing']);
        $b = ($this->open)(['subject' => 'Technique B']);
        ($this->console)()->setStatus($this->boss, $b, TicketStatus::WAITING_ON_YOU);
        ($this->console)()->setPriority($this->boss, $b, 'urgent');
        ($this->console)()->assign($this->boss, $a, $this->colleague->id);
        ($this->console)()->reply($this->boss, ($this->fresh)($a), 'Nous regardons votre facture.');
        $filter = fn (string $name, mixed $value, array $see, array $hide) => ($this->list)()->set('activeTab', 'everything')->filterTable($name, $value)->assertCanSeeTableRecords(($this->records)($see))->assertCanNotSeeTableRecords(($this->records)($hide));

        $filter('status', ['waiting_on_you'], [$b], [$a]);
        $filter('priority', ['urgent'], [$b], [$a]);
        $filter('category', ['billing'], [$a], [$b]);
        $filter('assignee', $this->colleague->id, [$a], [$b]);
        $filter('assignee', 'none', [$b], [$a]);
        $filter('source', ['contact_form'], [$a, $b], []);
        $filter('source', ['manual'], [], [$a, $b]);
        $filter('organization', $this->org->id, [$a, $b], []);
        $filter('organization', (string) Str::uuid(), [], [$a, $b]);
        $filter('created', ['from' => now()->toDateString(), 'until' => now()->toDateString()], [$a, $b], []);
        $filter('created', ['from' => now()->addDay()->toDateString()], [], [$a, $b]);
        $filter('needs_response', true, [$b], [$a]);
    });

    it('trie par priorité, ancienneté et attente', function (): void {
        $low = ($this->open)(['subject' => 'Priorité basse']);
        $this->travel(2)->seconds();
        $urgent = ($this->open)(['subject' => 'Priorité urgente']);
        ($this->console)()->setPriority($this->boss, $low, 'low');
        ($this->console)()->setPriority($this->boss, $urgent, 'urgent');

        ($this->list)()->set('activeTab', 'everything')->sortTable('priority_rank', 'desc')->assertCanSeeTableRecords(($this->records)([$urgent, $low]), inOrder: true);
        ($this->list)()->set('activeTab', 'everything')->sortTable('created_at', 'asc')->assertCanSeeTableRecords(($this->records)([$low, $urgent]), inOrder: true);
        ($this->list)()->set('activeTab', 'everything')->sortTable('last_client_message_at', 'desc')->assertCanSeeTableRecords(($this->records)([$urgent, $low]), inOrder: true);
    });

    it('applique les actions en lot : assigner, état, priorité, étiquette, fermeture', function (): void {
        $a = ($this->open)(['subject' => 'Premier ticket']);
        $b = ($this->open)(['subject' => 'Second ticket']);
        $both = ($this->records)([$a, $b]);

        ($this->list)()->set('activeTab', 'everything')
            ->callTableBulkAction('assign', $both, data: ['agent' => $this->agent->id])
            ->callTableBulkAction('priority', $both, data: ['priority' => 'high'])
            ->callTableBulkAction('tag', $both, data: ['tag' => 'Lot du jour'])
            ->callTableBulkAction('status', $both, data: ['status' => TicketStatus::IN_PROGRESS]);

        foreach ([$a, $b] as $ticket) {
            $after = ($this->fresh)($ticket);
            expect($after->assigned_admin_id)->toBe($this->agent->id)->and($after->priority())->toBe('high')->and($after->status)->toBe(TicketStatus::IN_PROGRESS)
                ->and($after->tags()->pluck('tag')->all())->toBe(['lot-du-jour']);
        }

        ($this->list)()->set('activeTab', 'everything')->callTableBulkAction('close', $both);
        expect(($this->fresh)($a)->status)->toBe(TicketStatus::CLOSED)->and(($this->fresh)($b)->status)->toBe(TicketStatus::CLOSED);

        // Un second passage ignore les tickets déjà fermés sans échouer.
        ($this->list)()->set('activeTab', 'everything')->callTableBulkAction('close', $both)->assertNotified();
        expect(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'support.tickets.bulk')->count())->toBe(6);
    });

    it('crée un ticket au nom d\'un membre du compte', function (): void {
        ($this->list)()->callAction('create', data: [
            'org_id' => $this->org->id, 'user_id' => $this->owner->id, 'subject' => 'Appel du gérant', 'category' => 'billing', 'priority' => 'high',
            'source' => 'manual', 'assigned_admin_id' => $this->agent->id, 'description' => 'Facture en double signalée par téléphone.',
        ])->assertHasNoActionErrors();

        $ticket = PlatformSupportTicket::query()->where('subject', 'Appel du gérant')->firstOrFail();
        expect($ticket->source)->toBe('manual')->and($ticket->status)->toBe(TicketStatus::OPEN)->and($ticket->assigned_admin_id)->toBe($this->agent->id);
    });

    it('exporte en CSV (Super Admin seulement), neutralise les formules et trace l\'export', function (): void {
        ($this->open)(['subject' => '=SOMME(A1:A9) piégé']);

        $page = ($this->list)()->set('activeTab', 'everything')->callAction('export');
        $page->assertFileDownloaded();
        expect(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'support.tickets.exported')->count())->toBe(1);

        ($this->list)($this->agent)->assertActionHidden('export');
    });

    it('enregistre le filtre en cours comme vue personnelle et la rouvre', function (): void {
        $a = ($this->open)(['subject' => 'Facturation A', 'category' => 'billing']);
        $b = ($this->open)(['subject' => 'Technique B']);

        ($this->list)()->set('activeTab', 'everything')->filterTable('category', ['billing'])->callAction('save_view', data: ['name' => 'Facturation'])->assertHasNoActionErrors();

        $row = DB::connection('pgsql_platform')->table('support_saved_views')->where('admin_id', $this->boss->id)->first();
        expect($row->name)->toBe('Facturation')->and(json_decode($row->filters, true)['category']['values'])->toBe(['billing']);

        ($this->list)()->callAction('saved_view_'.$row->id)->assertCanSeeTableRecords(($this->records)([$a]))->assertCanNotSeeTableRecords(($this->records)([$b]));
        // La vue est personnelle.
        expect(DB::connection('pgsql_platform')->table('support_saved_views')->where('admin_id', $this->agent->id)->count())->toBe(0);

        ($this->list)()->callAction('delete_view', data: ['view' => $row->id]);
        expect(DB::connection('pgsql_platform')->table('support_saved_views')->count())->toBe(0);
    });

    it('enregistre les préférences de notification de l\'agent connecté', function (): void {
        ($this->list)($this->agent)->callAction('preferences', data: ['new_ticket' => ['mail'], 'client_reply' => [], 'assigned' => ['database', 'mail'], 'priority_changed' => [], 'sla' => ['database'], 'ticket_done' => []])->assertHasNoActionErrors();

        expect($this->agent->refresh()->support_notifications)->toEqual(['new_ticket' => ['mail'], 'client_reply' => [], 'assigned' => ['database', 'mail'], 'priority_changed' => [], 'sla' => ['database'], 'ticket_done' => []]);
        expect($this->boss->refresh()->support_notifications)->toBeNull();
    });

    it('ne montre à un agent que ses tickets et les tickets non assignés', function (): void {
        $free = ($this->open)(['subject' => 'Sans agent']);
        $mine = ($this->open)(['subject' => 'Le mien']);
        $theirs = ($this->open)(['subject' => 'Celui du collègue']);
        ($this->console)()->assign($this->boss, $mine, $this->agent->id);
        ($this->console)()->assign($this->boss, $theirs, $this->colleague->id);

        ($this->list)($this->agent)->set('activeTab', 'everything')->assertCanSeeTableRecords(($this->records)([$free, $mine]))->assertCanNotSeeTableRecords(($this->records)([$theirs]));
    });

    it('montre un état vide clair', function (): void {
        ($this->list)()->assertSee(__('tickets.empty.heading'));
    });
});

describe('détail d\'un ticket', function (): void {
    it('affiche l\'en-tête et la conversation, avec la note interne bien distincte', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);
        ($this->console)()->reply($this->boss, ($this->fresh)($ticket), 'Nous regardons votre dossier.');
        ($this->console)()->note($this->boss, ($this->fresh)($ticket), 'Piste : jeton Meta expiré.');
        ($this->console)()->setPriority($this->boss, ($this->fresh)($ticket), 'high');

        ($this->page)($ticket)->assertSuccessful()
            ->assertSee($ticket->reference())->assertSee('Les rappels ne partent pas')->assertSee($this->org->name)->assertSee('contact@example.com')
            ->assertSee('agent@rendivoo.test')->assertSee('Depuis hier, aucun rappel WhatsApp')->assertSee('Nous regardons votre dossier.')
            ->assertSee(__('tickets.thread.internal_label'))->assertSee('Piste : jeton Meta expiré.')->assertSee(__('tickets.thread.sent_label'))
            ->assertSee(__('tickets.priorities.high'))->assertSee(__('tickets.composer.reply_banner', ['email' => 'contact@example.com']));
        expect(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'support.ticket.viewed')->count())->toBe(1);
    });

    it('envoie une réponse publique avec pièce jointe : e-mail, état, première réponse', function (): void {
        $ticket = ($this->open)();

        ($this->page)($ticket)->fillForm(['mode' => 'reply', 'body' => 'Voici la procédure à suivre.', 'status' => 'waiting_on_you', 'files' => [UploadedFile::fake()->image('guide.png', 40, 40)]])
            ->call('submit')->assertHasNoFormErrors();

        $after = ($this->fresh)($ticket);
        expect($after->status)->toBe(TicketStatus::WAITING_ON_YOU)->and($after->first_response_at)->not->toBeNull();
        $message = SupportMessage::on('pgsql_platform')->where('ticket_id', $ticket->id)->where('author_type', 'staff')->firstOrFail();
        expect($message->visibility)->toBe('public')->and($message->body)->toBe('Voici la procédure à suivre.')->and($message->attachments()->count())->toBe(1);
        Notification::assertSentOnDemand(TicketReplyNotification::class);
    });

    it('enregistre une note interne sans rien envoyer au client ni changer l\'état', function (): void {
        $ticket = ($this->open)();
        Notification::fake();

        ($this->page)($ticket)->fillForm(['mode' => 'internal', 'body' => 'Note de passage.'])->call('submit')->assertHasNoFormErrors();

        $after = ($this->fresh)($ticket);
        expect($after->status)->toBe(TicketStatus::NEW)->and($after->first_response_at)->toBeNull();
        expect(SupportMessage::on('pgsql_platform')->where('ticket_id', $ticket->id)->where('visibility', 'internal')->count())->toBe(1);
        Notification::assertNothingSent();
    });

    it('exige un texte avant d\'envoyer', function (): void {
        $ticket = ($this->open)();

        ($this->page)($ticket)->fillForm(['mode' => 'reply', 'body' => ''])->call('submit')->assertHasFormErrors(['body' => 'required']);
        expect(SupportMessage::on('pgsql_platform')->where('ticket_id', $ticket->id)->where('author_type', 'staff')->count())->toBe(0);
    });

    it('applique les actions de l\'en-tête : assigner, état, priorité, étiquette, lien, aide interne, résoudre, fermer, rouvrir', function (): void {
        $ticket = ($this->open)();
        $other = ($this->open)(['subject' => 'Un autre ticket']);

        ($this->page)($ticket)
            ->callAction('assign', data: ['agent' => $this->agent->id])
            ->callAction('priority', data: ['priority' => 'urgent'])
            ->callAction('status', data: ['status' => 'in_progress'])
            ->callAction('tags', data: ['tag' => 'Remboursement'])
            ->callAction('link', data: ['kind' => 'ticket', 'label' => $other->reference()])
            ->callAction('link', data: ['kind' => 'booking', 'label' => 'RDV du 12/10'])
            ->callAction('help', data: ['team' => 'billing', 'message' => 'Vérifier le paiement.']);

        $after = ($this->fresh)($ticket);
        expect($after->assigned_admin_id)->toBe($this->agent->id)->and($after->priority())->toBe('urgent')->and($after->status)->toBe(TicketStatus::WAITING_INTERNAL)
            ->and($after->tags()->pluck('tag')->all())->toBe(['remboursement']);
        $links = $after->links()->orderBy('kind')->get();
        expect($links->pluck('kind')->all())->toBe(['booking', 'ticket'])->and($links->firstWhere('kind', 'ticket')->ref_id)->toBe($other->id)->and($links->firstWhere('kind', 'ticket')->label)->toContain('Un autre ticket');

        ($this->page)($ticket)->callAction('resolve');
        expect(($this->fresh)($ticket)->status)->toBe(TicketStatus::RESOLVED);
        ($this->page)($ticket)->callAction('reopen');
        expect(($this->fresh)($ticket)->status)->toBe(TicketStatus::REOPENED);
        ($this->page)($ticket)->callAction('close');
        expect(($this->fresh)($ticket)->status)->toBe(TicketStatus::CLOSED);
        ($this->page)($ticket)->assertSee(__('tickets.composer.closed_title'))->assertActionHidden('assign');
    });

    it('retire une étiquette et un lien depuis la fiche', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->addTag($this->boss, $ticket, 'vip');
        ($this->console)()->link($this->boss, $ticket, 'customer', 'Camille Client');
        $link = ($this->fresh)($ticket)->links()->firstOrFail();

        ($this->page)($ticket)->call('removeTag', 'vip')->call('removeLink', $link->id);

        expect(($this->fresh)($ticket)->tags()->count())->toBe(0)->and(($this->fresh)($ticket)->links()->count())->toBe(0);
    });

    it('signale une modification faite ailleurs et refuse d\'agir avant l\'actualisation', function (): void {
        $ticket = ($this->open)();

        $page = ($this->page)($ticket);
        ($this->console)()->assign($this->colleague, $ticket, $this->colleague->id);

        $page->call('checkForChanges')->assertSet('stale', true)->assertSee(__('tickets.stale.title'));
        $page->callAction('priority', data: ['priority' => 'urgent']);
        expect(($this->fresh)($ticket)->priority())->toBe('medium');

        $page->call('refreshTicket')->assertSet('stale', false)->callAction('priority', data: ['priority' => 'urgent']);
        expect(($this->fresh)($ticket)->priority())->toBe('urgent');
    });

    it('interdit à un agent le ticket d\'un collègue', function (): void {
        $theirs = ($this->open)();
        ($this->console)()->assign($this->boss, $theirs, $this->colleague->id);

        ($this->page)($theirs, $this->agent)->assertForbidden();
        ($this->as)($this->agent)->get(SupportTicketResource::getUrl('view', ['record' => $theirs->id]))->assertForbidden();
        ($this->page)($theirs, $this->colleague)->assertSuccessful();
    });
});

describe('pièces jointes', function (): void {
    it('les télécharge par une route contrôlée et tracée, jamais par une adresse publique', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->reply($this->boss, $ticket, 'Voir la capture.', null, [UploadedFile::fake()->image('capture.png', 40, 40)]);
        $file = SupportAttachment::on('pgsql_platform')->firstOrFail();

        $this->get(route('filament.admin.support.attachment', $file->id))->assertRedirect('/admin/login');
        $this->actingAs($this->owner, 'web')->get(route('filament.admin.support.attachment', $file->id))->assertRedirect('/admin/login');

        ($this->as)($this->boss)->get(route('filament.admin.support.attachment', $file->id))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        expect(DB::connection('pgsql_platform')->table('audit_logs')->where('action', 'support.attachment.downloaded')->count())->toBe(1);

        ($this->console)()->assign($this->boss, ($this->fresh)($ticket), $this->colleague->id);
        ($this->as)($this->agent)->get(route('filament.admin.support.attachment', $file->id))->assertForbidden();
        ($this->as)($this->colleague)->get(route('filament.admin.support.attachment', $file->id))->assertOk();
    });
});

describe('rapports', function (): void {
    it('affiche les chiffres, leurs définitions et suit les filtres', function (): void {
        $a = ($this->open)(['subject' => 'Ticket facturation', 'category' => 'billing']);
        ($this->open)(['subject' => 'Ticket technique']);
        ($this->console)()->setStatus($this->boss, $a, TicketStatus::WAITING_ON_YOU);

        $page = Livewire::actingAs($this->boss, 'platform')->test(SupportReports::class);
        $page->assertSuccessful()->assertSee(__('tickets.reports.cards.open'))->assertSee(__('tickets.reports.defs.first_response.text'))->assertSee(__('tickets.reports.defs.reopened.text'));
        expect($page->instance()->metrics())->toMatchArray(['created' => 2, 'open' => 2, 'waiting_on_client' => 1]);

        $page->fillForm(['category' => 'billing']);
        expect($page->instance()->metrics())->toMatchArray(['created' => 1]);
    });

    it('limite un agent à sa portée', function (): void {
        $mine = ($this->open)(['subject' => 'Le mien']);
        $theirs = ($this->open)(['subject' => 'Celui du collègue']);
        ($this->console)()->assign($this->boss, $mine, $this->agent->id);
        ($this->console)()->assign($this->boss, $theirs, $this->colleague->id);

        $page = Livewire::actingAs($this->agent, 'platform')->test(SupportReports::class);

        expect($page->instance()->metrics())->toMatchArray(['created' => 1]);
    });
});

describe('réglages du délai de première réponse', function (): void {
    it('expose les cinq réglages dans les paramètres globaux, avec leurs bornes', function (): void {
        $keys = array_keys(PlatformSettings::INTEGERS);

        expect($keys)->toContain('support_sla_urgent_hours', 'support_sla_high_hours', 'support_sla_medium_hours', 'support_sla_low_hours', 'support_at_risk_percent');
        expect(DB::table('platform_settings')->whereIn('key', $keys)->count())->toBe(count($keys));
        ($this->as)($this->boss)->get('/admin/platform-settings')->assertOk()->assertSee(__('admin.settings.keys.support_sla_medium_hours.label'));
    });
});
