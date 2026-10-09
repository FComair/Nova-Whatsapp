<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Models\SupportAttachment;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketEvent;
use App\Modules\Support\Notifications\TicketCreatedNotification;
use App\Modules\Support\Notifications\TicketReplyNotification;
use App\Modules\Support\Notifications\TicketResolvedNotification;
use App\Modules\Support\Services\TicketDesk;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Support client (docs/22, phase 2) : création, liste, conversation, états, pièces jointes, e-mails, pastille, isolation.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    Storage::fake('local');

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->colleague = $this->asOwner(function (): User {
        $user = User::factory()->create(['email' => 'collegue@example.com']);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist']);

        return $user;
    });
    $this->payload = fn (array $overrides = []): array => [
        'subject' => 'Les rappels ne partent pas',
        'category' => 'technical',
        'related_to' => 'whatsapp',
        'urgency' => 'medium',
        'description' => 'Depuis hier, aucun rappel WhatsApp n\'est envoyé à mes clients.',
        'email' => 'contact@example.com',
        'token' => (string) Str::uuid(),
        ...$overrides,
    ];
    $this->open = fn (?User $user = null, array $overrides = []): SupportTicket => (function () use ($user, $overrides): SupportTicket {
        ($this->as)($user)->post('/aide/contact', ($this->payload)($overrides))->assertSessionHasNoErrors();

        return $this->asOwner(fn () => SupportTicket::query()->latest('created_at')->latest('reference_number')->firstOrFail());
    })();
    $this->desk = fn (callable $callback): mixed => $this->asOwner(fn () => $callback(app(TicketDesk::class)));
    $this->fresh = fn (SupportTicket $ticket): SupportTicket => $this->asOwner(fn () => SupportTicket::query()->findOrFail($ticket->id));
});

describe('création', function (): void {
    it('exige d\'être connecté', function (): void {
        $this->get('/aide/contact')->assertRedirect('/connexion');
        $this->get('/aide/tickets')->assertRedirect('/connexion');
    });

    it('affiche le formulaire avec l\'adresse du compte', function (): void {
        ($this->as)()->get('/aide/contact')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Help/Contact')
            ->where('email', $this->owner->email)
            ->has('token')
            ->where('options.categories', SupportTicket::CATEGORIES)
            ->where('limits.files', 3));
    });

    it('crée le ticket, son premier message et son historique, et confirme par e-mail', function (): void {
        Notification::fake();

        $response = ($this->as)()->post('/aide/contact', ($this->payload)());
        $ticket = $this->asOwner(fn () => SupportTicket::query()->firstOrFail());

        $response->assertRedirect('/aide/contact/envoye/'.$ticket->reference());
        expect($ticket->reference())->toMatch('/^NWS-\d{4,}$/')->and($ticket->status)->toBe('open')->and($ticket->user_id)->toBe($this->owner->id)->and($ticket->reply_email)->toBe('contact@example.com');
        expect($this->asOwner(fn () => SupportMessage::query()->where('ticket_id', $ticket->id)->value('author_type')))->toBe('client');
        expect($this->asOwner(fn () => SupportTicketEvent::query()->where('ticket_id', $ticket->id)->value('action')))->toBe('created');
        Notification::assertSentOnDemand(TicketCreatedNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'contact@example.com');

        ($this->as)()->get('/aide/contact/envoye/'.$ticket->reference())->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help/TicketSent')->where('ticket.reference', $ticket->reference())->where('email', 'contact@example.com'));
    });

    it('ne crée jamais deux tickets pour le même formulaire envoyé deux fois', function (): void {
        $payload = ($this->payload)();

        ($this->as)()->post('/aide/contact', $payload)->assertSessionHasNoErrors();
        ($this->as)()->post('/aide/contact', $payload)->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => SupportTicket::query()->count()))->toBe(1);
    });

    it('valide le formulaire', function (string $field, mixed $value): void {
        ($this->as)()->post('/aide/contact', ($this->payload)([$field => $value]))->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => SupportTicket::query()->count()))->toBe(0);
    })->with([
        'objet trop court' => ['subject', 'Hé'],
        'catégorie inconnue' => ['category', 'piratage'],
        'rubrique inconnue' => ['related_to', 'autre-chose'],
        'urgence inconnue' => ['urgency', 'critique'],
        'description trop courte' => ['description', 'Trop court'],
        'e-mail invalide' => ['email', 'pas-un-email'],
        'jeton manquant' => ['token', ''],
    ]);

    it('limite à 5 nouveaux tickets par heure et par personne', function (): void {
        foreach (range(1, 5) as $i) {
            ($this->as)()->post('/aide/contact', ($this->payload)(['subject' => "Demande numéro {$i}"]))->assertSessionHasNoErrors();
        }

        ($this->as)()->post('/aide/contact', ($this->payload)(['subject' => 'Demande de trop']))->assertSessionHasErrors('rate');
        expect($this->asOwner(fn () => SupportTicket::query()->count()))->toBe(5);
    });

    it('préremplit l\'objet depuis un article d\'aide', function (): void {
        ($this->as)()->get('/aide/contact?category=whatsapp&article=connect-whatsapp')->assertInertia(fn (Assert $page) => $page->where('subject', 'Connecter votre numéro WhatsApp'));
    });
});

describe('pièces jointes', function (): void {
    it('accepte une image et un PDF, les range sur le disque privé et masque le nom d\'origine dangereux', function (): void {
        $png = UploadedFile::fake()->image('capture ../écran.png', 40, 40);
        $pdf = UploadedFile::fake()->create('facture.pdf', 100, 'application/pdf');

        ($this->as)()->post('/aide/contact', [...($this->payload)(), 'attachments' => [$png, $pdf]])->assertSessionHasNoErrors();

        $files = $this->asOwner(fn () => SupportAttachment::query()->orderBy('original_name')->get());
        expect($files)->toHaveCount(2);

        foreach ($files as $file) {
            expect($file->path)->toStartWith("support/{$this->org->id}/")->and($file->original_name)->not->toContain('/')->not->toContain('..');
            Storage::disk('local')->assertExists($file->path);
        }
    });

    it('refuse plus de 3 fichiers, un fichier trop gros et un faux PNG', function (): void {
        $four = array_map(fn (int $i) => UploadedFile::fake()->image("{$i}.png"), range(1, 4));
        ($this->as)()->post('/aide/contact', [...($this->payload)(), 'attachments' => $four])->assertSessionHasErrors('attachments');

        ($this->as)()->post('/aide/contact', [...($this->payload)(), 'attachments' => [UploadedFile::fake()->create('gros.pdf', 6000, 'application/pdf')]])->assertSessionHasErrors('attachments.0');

        $path = tempnam(sys_get_temp_dir(), 'nova');
        file_put_contents($path, '<?php echo "pirate";');
        $fake = new UploadedFile($path, 'image.png', 'image/png', null, true);
        ($this->as)()->post('/aide/contact', [...($this->payload)(), 'attachments' => [$fake]])->assertSessionHasErrors('attachments.0');

        expect($this->asOwner(fn () => SupportTicket::query()->count()))->toBe(0);
    });

    it('ne laisse télécharger une pièce jointe qu\'aux personnes qui ont accès au ticket', function (): void {
        ($this->as)($this->colleague)->post('/aide/contact', [...($this->payload)(), 'attachments' => [UploadedFile::fake()->create('note.pdf', 10, 'application/pdf')]])->assertSessionHasNoErrors();
        $ticket = $this->asOwner(fn () => SupportTicket::query()->firstOrFail());
        $file = $this->asOwner(fn () => SupportAttachment::query()->firstOrFail());
        $url = "/aide/tickets/{$ticket->reference()}/pieces/{$file->id}";

        ($this->as)($this->colleague)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        ($this->as)()->get($url)->assertOk();

        $stranger = $this->asOwner(function (): User {
            $user = User::factory()->create(['email' => 'tiers@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'practitioner']);

            return $user;
        });
        ($this->as)($stranger)->get($url)->assertNotFound();
    });
});

describe('isolation', function (): void {
    it('montre à chacun ses tickets, et au propriétaire ceux de son établissement', function (): void {
        $mine = ($this->open)($this->owner, ['subject' => 'Ticket du propriétaire']);
        $theirs = ($this->open)($this->colleague, ['subject' => 'Ticket du collègue']);

        ($this->as)($this->colleague)->get('/aide/tickets')->assertInertia(fn (Assert $page) => $page->has('tickets', 1)->where('tickets.0.reference', $theirs->reference())->where('canSeeAll', false));
        ($this->as)()->get('/aide/tickets')->assertInertia(fn (Assert $page) => $page->has('tickets', 1)->where('tickets.0.reference', $mine->reference())->where('canSeeAll', true));
        ($this->as)()->get('/aide/tickets?scope=all')->assertInertia(fn (Assert $page) => $page->has('tickets', 2));
        ($this->as)($this->colleague)->get('/aide/tickets?scope=all')->assertInertia(fn (Assert $page) => $page->has('tickets', 1));

        ($this->as)($this->colleague)->get("/aide/tickets/{$mine->reference()}")->assertNotFound();
        ($this->as)()->get("/aide/tickets/{$theirs->reference()}")->assertOk();
        ($this->as)($this->colleague)->post("/aide/tickets/{$mine->reference()}/reponse", ['body' => 'Je me permets de répondre'])->assertNotFound();
        ($this->as)($this->colleague)->post("/aide/tickets/{$mine->reference()}/fermer")->assertNotFound();
    });

    it('ne montre jamais les tickets d\'un autre établissement', function (): void {
        ($this->open)($this->owner, ['subject' => 'Ticket de chez nous']);
        ['user' => $other, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');
        $ticket = $this->asOwner(fn () => SupportTicket::query()->firstOrFail());

        $this->signedIn($other, $otherOrg)->get('/aide/tickets?scope=all')->assertInertia(fn (Assert $page) => $page->has('tickets', 0)->where('hasAny', false));
        $this->signedIn($other, $otherOrg)->get("/aide/tickets/{$ticket->reference()}")->assertNotFound();
    });
});

describe('liste', function (): void {
    it('filtre par état, catégorie et urgence, et cherche par référence, objet ou message', function (): void {
        $a = ($this->open)(null, ['subject' => 'Rappels WhatsApp absents', 'category' => 'technical', 'urgency' => 'high']);
        $b = ($this->open)(null, ['subject' => 'Question de facturation', 'category' => 'billing', 'urgency' => 'low', 'description' => 'Pouvez-vous me renvoyer la facture du mois de septembre ?']);
        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($b), null, 'Voici votre facture.', 'resolved'));

        $refs = fn (string $query): array => collect(($this->as)()->get('/aide/tickets?'.$query)->viewData('page')['props']['tickets'])->pluck('reference')->sort()->values()->all();

        expect($refs('status=open'))->toBe([$a->reference()]);
        expect($refs('status=done'))->toBe([$b->reference()]);
        expect($refs('category=billing'))->toBe([$b->reference()]);
        expect($refs('urgency=high'))->toBe([$a->reference()]);
        expect($refs('q='.urlencode('whatsapp')))->toBe([$a->reference()]);
        expect($refs('q='.urlencode('septembre')))->toBe([$b->reference()]);
        expect($refs('q='.$a->reference_number))->toBe([$a->reference()]);
        expect($refs('q='.urlencode(strtolower($b->reference()))))->toBe([$b->reference()]);
        expect($refs('status=nimporte&category=x'))->toHaveCount(2);
    });

    it('trie par date de création et pagine', function (): void {
        foreach (range(1, 27) as $i) {
            $this->asOwner(fn () => SupportTicket::query()->create([
                'org_id' => $this->org->id, 'user_id' => $this->owner->id, 'reply_email' => 'a@b.be', 'locale' => 'fr', 'subject' => "Sujet {$i}", 'category' => 'other', 'urgency' => 'low',
                'status' => 'open', 'last_message_at' => now()->subMinutes(30 - $i),
            ]));
        }

        ($this->as)()->get('/aide/tickets')->assertInertia(fn (Assert $page) => $page->has('tickets', 25)->where('pagination.last_page', 2)->where('tickets.0.subject', 'Sujet 27'));
        ($this->as)()->get('/aide/tickets?page=2&sort=created&dir=asc')->assertInertia(fn (Assert $page) => $page->has('tickets', 2));
    });
});

describe('conversation et états', function (): void {
    it('répond : « En attente de vous » repasse « En cours », « Résolu » se rouvre, « Fermé » refuse', function (): void {
        $ticket = ($this->open)();
        $reply = fn (string $text = 'Voici la précision demandée.') => ($this->as)()->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => $text]);

        $reply()->assertSessionHasNoErrors();
        expect(($this->fresh)($ticket)->status)->toBe('open');

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Pouvez-vous préciser ?', 'waiting_on_you'));
        expect(($this->fresh)($ticket)->status)->toBe('waiting_on_you');
        $reply()->assertSessionHasNoErrors()->assertSessionHas('status', 'Votre réponse a été envoyée.');
        expect(($this->fresh)($ticket)->status)->toBe('in_progress');

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'C\'est réglé.', 'resolved'));
        expect(($this->fresh)($ticket)->status)->toBe('resolved');
        $reply('Cela ne fonctionne toujours pas.')->assertSessionHasNoErrors();
        expect(($this->fresh)($ticket))->status->toBe('open')->resolved_at->toBeNull();
        expect($this->asOwner(fn () => SupportTicketEvent::query()->where('ticket_id', $ticket->id)->where('action', 'reopened')->exists()))->toBeTrue();

        ($this->as)()->post("/aide/tickets/{$ticket->reference()}/fermer")->assertSessionHasNoErrors();
        $reply()->assertSessionHasErrors('body');
        expect($this->asOwner(fn () => SupportMessage::query()->where('ticket_id', $ticket->id)->where('author_type', 'client')->count()))->toBe(4);
    });

    it('valide la réponse', function (): void {
        $ticket = ($this->open)();

        ($this->as)()->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => ''])->assertSessionHasErrors('body');
        ($this->as)()->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => str_repeat('a', 5001)])->assertSessionHasErrors('body');
    });

    it('ferme, confirme « résolu », et rouvre dans les 14 jours seulement', function (): void {
        $ticket = ($this->open)();
        $url = fn (string $action): string => "/aide/tickets/{$ticket->reference()}/{$action}";

        ($this->as)()->post($url('resolu'))->assertSessionHasErrors('ticket');
        ($this->as)()->post($url('rouvrir'))->assertSessionHasErrors('ticket');

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Réglé.', 'resolved'));
        ($this->as)()->post($url('fermer'))->assertSessionHasErrors('ticket');
        ($this->as)()->post($url('rouvrir'))->assertSessionHasNoErrors();
        expect(($this->fresh)($ticket)->status)->toBe('open');

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Réglé cette fois.', 'resolved'));
        $this->travel(15)->days();
        ($this->as)()->post($url('rouvrir'))->assertSessionHasErrors('ticket');
        ($this->as)()->post($url('resolu'))->assertSessionHasNoErrors();

        expect(($this->fresh)($ticket))->status->toBe('closed')->closed_reason->toBe('client');
        ($this->as)()->post($url('fermer'))->assertSessionHasErrors('ticket');
    });

    it('affiche la conversation, ses pièces jointes et l\'historique, et les actions permises', function (): void {
        $ticket = ($this->open)();
        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Nous regardons cela.'));

        ($this->as)()->get("/aide/tickets/{$ticket->reference()}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Help/Ticket')
            ->where('ticket.status', 'in_progress')
            ->has('thread', 3)
            ->where('thread.0.from', 'you')
            ->where('thread.1.from', 'staff')
            ->where('thread.2.type', 'event')
            ->where('thread.2.to', 'in_progress')
            ->where('can', ['reply' => true, 'close' => true, 'solved' => false, 'reopen' => false]));
    });
});

describe('équipe', function (): void {
    it('prévient le client par e-mail d\'une réponse puis d\'une résolution, et passe « Ouvert » à « En cours »', function (): void {
        $ticket = ($this->open)();
        Notification::fake();

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Bonjour, nous regardons.'));
        expect(($this->fresh)($ticket)->status)->toBe('in_progress');
        Notification::assertSentOnDemand(TicketReplyNotification::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'contact@example.com');

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'C\'est réglé.', 'resolved'));
        Notification::assertSentOnDemand(TicketResolvedNotification::class);
        expect(($this->fresh)($ticket)->resolved_at)->not->toBeNull();
    });

    it('refuse un état que l\'équipe ne peut pas choisir et toute réponse sur un ticket fermé', function (): void {
        $ticket = ($this->open)();

        expect(fn () => ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Texte', 'closed')))->toThrow(TicketRefused::class);

        ($this->as)()->post("/aide/tickets/{$ticket->reference()}/fermer");
        expect(fn () => ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Texte')))->toThrow(TicketRefused::class);
    });

    it('garde les notes internes invisibles du client, jusque dans la base', function (): void {
        $ticket = ($this->open)();
        ($this->desk)(fn (TicketDesk $desk) => $desk->staffNote(($this->fresh)($ticket), null, 'Client pressé, à traiter en priorité.'));
        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Réponse visible.'));

        ($this->as)()->get("/aide/tickets/{$ticket->reference()}")->assertInertia(fn (Assert $page) => $page->where('thread', fn ($thread) => collect($thread)->pluck('body')->filter()->doesntContain('Client pressé, à traiter en priorité.')));
        ($this->as)()->get('/aide/tickets?q='.urlencode('pressé'))->assertInertia(fn (Assert $page) => $page->has('tickets', 0));

        expect($this->asOwner(fn () => SupportMessage::query()->where('ticket_id', $ticket->id)->where('visibility', 'internal')->count()))->toBe(1);
        $asApp = app(TenantContext::class)->run($this->org->id, fn () => SupportMessage::query()->where('ticket_id', $ticket->id)->pluck('visibility')->unique()->all());
        expect($asApp)->toBe(['public']);
    });

    it('empêche l\'application de se faire passer pour l\'équipe ou de toucher aux champs internes', function (): void {
        $ticket = ($this->open)();
        $insert = fn (array $row) => fn () => app(TenantContext::class)->run($this->org->id, fn () => DB::table('support_messages')->insert([
            'org_id' => $this->org->id, 'ticket_id' => $ticket->id, 'body' => 'Faux', ...$row,
        ]));

        expect($insert(['author_type' => 'staff', 'visibility' => 'public']))->toThrow(QueryException::class);
        expect($insert(['author_type' => 'client', 'visibility' => 'internal']))->toThrow(QueryException::class);
        expect(fn () => app(TenantContext::class)->run($this->org->id, fn () => DB::table('support_tickets')->where('id', $ticket->id)->update(['staff_priority' => 'high'])))->toThrow(QueryException::class);
        expect(fn () => app(TenantContext::class)->run($this->org->id, fn () => DB::table('support_tickets')->where('id', $ticket->id)->delete()))->toThrow(QueryException::class);
        expect(fn () => $this->asOwner(fn () => DB::statement('update support_messages set body = ?', ['x'])))->toThrow(QueryException::class);
    });
});

describe('pastille du menu', function (): void {
    it('compte les tickets qui attendent la personne et redescend une fois la réponse lue', function (): void {
        $ticket = ($this->open)();
        $attention = fn (): int => ($this->as)()->get('/aide')->viewData('page')['props']['support']['attention'];

        expect($attention())->toBe(0);

        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Nous avons une question.', 'waiting_on_you'));
        expect($attention())->toBe(1);

        ($this->as)()->get("/aide/tickets/{$ticket->reference()}")->assertOk();
        expect($attention())->toBe(1); // « En attente de vous » reste à traiter tant qu'on n'a pas répondu

        ($this->as)()->post("/aide/tickets/{$ticket->reference()}/reponse", ['body' => 'Voici ma réponse.']);
        expect($attention())->toBe(0);

        $this->travel(5)->seconds();
        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Merci, on continue.', 'in_progress'));
        expect($attention())->toBe(1);
        ($this->as)()->get("/aide/tickets/{$ticket->reference()}");
        expect($attention())->toBe(0);
    });

    it('ne compte que les tickets de la personne, pas ceux d\'un collègue', function (): void {
        $ticket = ($this->open)($this->colleague);
        ($this->desk)(fn (TicketDesk $desk) => $desk->staffReply(($this->fresh)($ticket), null, 'Question.', 'waiting_on_you'));

        expect(($this->as)()->get('/aide')->viewData('page')['props']['support']['attention'])->toBe(0);
        expect(($this->as)($this->colleague)->get('/aide')->viewData('page')['props']['support']['attention'])->toBe(1);
    });
});
