<?php

declare(strict_types=1);

use App\Modules\Admin\Filament\Resources\FeedbackResource;
use App\Modules\Admin\Filament\Resources\FeedbackResource\Pages\ListFeedback;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformFeedback;
use App\Modules\Feedback\Models\RendivooFeedback;
use App\Modules\Feedback\Services\ReviewLinks;
use App\Modules\Identity\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\TicketDesk;
use App\Modules\Tenancy\Models\OrgMember;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Avis des clients sur Rendivoo (docs/26) : avis privé de 1 à 5 étoiles à tout moment ou à propos d'un ticket, invitations discrètes et
 * refusables, liens d'avis publics facultatifs et identiques pour tous (aucun filtrage selon la note, aucune contrepartie), lecture
 * par le Super Admin séparée de tout avis public.
 */
const GOOGLE = 'https://g.page/r/rendivoo/review';
const TRUSTPILOT = 'https://www.trustpilot.com/evaluate/rendivoo.example';

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    Storage::fake('local');
    config(['rendivoo.reviews.google' => GOOGLE, 'rendivoo.reviews.trustpilot' => TRUSTPILOT]);

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->colleague = $this->asOwner(function (): User {
        $user = User::factory()->create(['email' => 'collegue@example.com']);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist']);

        return $user;
    });

    $this->open = function (?User $user = null): SupportTicket {
        ($this->as)($user)->post('/aide/contact', [
            'subject' => 'Les rappels ne partent pas', 'category' => 'technical', 'related_to' => 'whatsapp', 'urgency' => 'medium',
            'description' => 'Depuis hier, aucun rappel WhatsApp n\'est envoyé.', 'email' => 'contact@example.com', 'token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return $this->asOwner(fn () => SupportTicket::query()->latest('created_at')->latest('reference_number')->firstOrFail());
    };
    $this->staff = fn (SupportTicket $ticket, ?string $status = null) => $this->asOwner(fn () => app(TicketDesk::class)->staffReply(SupportTicket::query()->findOrFail($ticket->id), null, 'Nous regardons cela.', $status));
    $this->give = fn (array $data, ?User $user = null) => ($this->as)($user)->post('/aide/avis', $data);
    $this->all = fn (): array => $this->asOwner(fn () => RendivooFeedback::query()->orderBy('created_at')->get()->all());
    $this->prompt = fn (SupportTicket $ticket, ?User $user = null) => ($this->props)('/aide/tickets/'.$ticket->reference(), $user)['feedback_prompt'];
    $this->props = fn (string $url, ?User $user = null): array => Assert::fromTestResponse(($this->as)($user)->get($url)->assertOk())->toArray()['props'];
});

describe('avis privé à tout moment', function (): void {
    it('exige d\'être connecté', function (): void {
        $this->get('/aide/avis')->assertRedirect('/connexion');
        $this->post('/aide/avis', ['rating' => 5])->assertRedirect('/connexion');
    });

    it('affiche le formulaire, accessible à tous les rôles', function (): void {
        foreach ([$this->owner, $this->colleague] as $user) {
            ($this->as)($user)->get('/aide/avis')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Help/Feedback')->where('ticket', null)->where('sent', null)->where('already_sent', false)->where('limits.comment', 2000));
        }
    });

    it('enregistre une note et un commentaire, sans ticket', function (): void {
        ($this->give)(['rating' => 4, 'comment' => '  Très pratique pour mes rendez-vous.  '])->assertSessionHasNoErrors()->assertRedirect('/aide/avis');

        [$feedback] = ($this->all)();

        expect($feedback->rating)->toBe(4)->and($feedback->comment)->toBe('Très pratique pour mes rendez-vous.')->and($feedback->source)->toBe('general')
            ->and($feedback->ticket_id)->toBeNull()->and($feedback->user_id)->toBe($this->owner->id)->and($feedback->org_id)->toBe($this->org->id)->and($feedback->locale)->toBe('fr');
    });

    it('accepte toutes les notes, y compris les plus basses, sans commentaire', function (int $rating): void {
        ($this->give)(['rating' => $rating])->assertSessionHasNoErrors();
        ($this->give)(['rating' => $rating, 'comment' => ''])->assertSessionHasNoErrors();

        $stored = ($this->all)();

        expect($stored)->toHaveCount(2)->and($stored[0]->rating)->toBe($rating)->and($stored[0]->comment)->toBeNull()->and($stored[1]->comment)->toBeNull();
    })->with([1, 2, 3, 4, 5]);

    it('refuse une note absente ou hors de 1 à 5, un commentaire trop long ou un ticket mal formé, et ne garde rien', function (array $data, string $field): void {
        ($this->give)($data)->assertSessionHasErrors($field);

        expect(($this->all)())->toBe([]);
    })->with([
        'sans note' => [[], 'rating'],
        'note nulle' => [['rating' => 0], 'rating'],
        'note trop haute' => [['rating' => 6], 'rating'],
        'note illisible' => [['rating' => 'abc'], 'rating'],
        'note décimale' => [['rating' => 3.5], 'rating'],
        'commentaire trop long' => [['rating' => 3, 'comment' => str_repeat('a', 2001)], 'comment'],
        'ticket mal formé' => [['rating' => 3, 'ticket' => 'pas-un-ticket'], 'ticket'],
    ]);

    it('dit clairement ce qui ne va pas, dans la langue de l\'interface', function (): void {
        ($this->give)(['rating' => 9])->assertSessionHasErrors(['rating' => 'Choisissez une note de 1 à 5 étoiles.']);
        ($this->give)(['rating' => 3, 'comment' => str_repeat('a', 2001)])->assertSessionHasErrors(['comment' => 'Votre commentaire ne doit pas dépasser 2000 caractères.']);
    });

    it('confirme l\'envoi et rend le message de remerciement', function (): void {
        ($this->give)(['rating' => 5, 'comment' => 'Merci'])->assertRedirect('/aide/avis');

        $props = ($this->props)('/aide/avis');
        expect($props['sent'])->toBe(($this->all)()[0]->id);

        // Le message n'apparaît qu'une fois : il ne revient pas à l'actualisation.
        expect(($this->props)('/aide/avis')['sent'])->toBeNull();
    });

    it('permet plusieurs avis généraux dans le temps', function (): void {
        ($this->give)(['rating' => 3]);
        ($this->give)(['rating' => 5, 'comment' => 'Bien mieux depuis la dernière mise à jour.']);

        expect(($this->all)())->toHaveCount(2);
    });

    it('limite la fréquence d\'envoi', function (): void {
        foreach (range(1, 10) as $i) {
            ($this->give)(['rating' => 3])->assertSessionHasNoErrors();
        }

        ($this->give)(['rating' => 3])->assertStatus(429);
    });

    it('est un journal en ajout seul : ni modification ni suppression par l\'application', function (): void {
        ($this->give)(['rating' => 3]);

        expect(fn () => $this->asOwner(fn () => DB::statement('update nova_feedback set rating = 5')))->toThrow(QueryException::class)
            ->and(fn () => $this->asOwner(fn () => DB::statement('delete from nova_feedback')))->toThrow(QueryException::class);
        expect(($this->all)()[0]->rating)->toBe(3);
    });
});

describe('avis à propos d\'un ticket', function (): void {
    it('rattache l\'avis au ticket et en garde la référence', function (): void {
        $ticket = ($this->open)();

        ($this->give)(['rating' => 5, 'ticket' => $ticket->reference()])->assertSessionHasNoErrors()->assertRedirect('/aide/avis?ticket='.$ticket->reference());

        [$feedback] = ($this->all)();
        expect($feedback->ticket_id)->toBe($ticket->id);
    });

    it('distingue « intervention » (l\'équipe a répondu), « ticket » (résolu) et « ticket » (fermé)', function (): void {
        $answered = ($this->open)();
        ($this->staff)($answered);
        $solved = ($this->open)();
        ($this->staff)($solved, 'resolved');
        $closed = ($this->open)();
        $this->asOwner(fn () => app(TicketDesk::class)->close(SupportTicket::query()->findOrFail($closed->id), $this->owner));

        foreach ([$answered, $solved, $closed] as $ticket) {
            ($this->give)(['rating' => 4, 'ticket' => $ticket->reference()])->assertSessionHasNoErrors();
        }

        $sources = collect(($this->all)())->pluck('source', 'ticket_id');
        expect($sources[$answered->id])->toBe('intervention')->and($sources[$solved->id])->toBe('ticket')->and($sources[$closed->id])->toBe('ticket');
    });

    it('affiche la référence du ticket dans le formulaire', function (): void {
        $ticket = ($this->open)();

        $props = ($this->props)('/aide/avis?ticket='.$ticket->reference());

        expect($props['ticket'])->toBe(['reference' => $ticket->reference(), 'subject' => 'Les rappels ne partent pas']);
    });

    it('refuse un ticket inconnu ou celui d\'un collègue (hors propriétaire)', function (): void {
        $mine = ($this->open)($this->colleague);

        ($this->as)($this->colleague)->get('/aide/avis?ticket=NWS-999999')->assertNotFound();
        ($this->as)($this->colleague)->post('/aide/avis', ['rating' => 3, 'ticket' => 'NWS-999999'])->assertNotFound();

        $theirs = ($this->open)($this->owner);
        ($this->as)($this->colleague)->get('/aide/avis?ticket='.$theirs->reference())->assertNotFound();
        ($this->as)($this->colleague)->post('/aide/avis', ['rating' => 3, 'ticket' => $theirs->reference()])->assertNotFound();

        // Le propriétaire voit les tickets de son établissement, comme dans l'aide.
        ($this->as)($this->owner)->get('/aide/avis?ticket='.$mine->reference())->assertOk();
        expect(($this->all)())->toBe([]);
    });

    it('n\'accepte qu\'un avis par personne et par ticket, et le dit', function (): void {
        $ticket = ($this->open)();

        ($this->give)(['rating' => 4, 'ticket' => $ticket->reference()])->assertSessionHasNoErrors();
        ($this->give)(['rating' => 1, 'ticket' => $ticket->reference()])->assertSessionHasErrors(['ticket' => 'Vous avez déjà donné votre avis sur ce ticket.']);

        expect(($this->all)())->toHaveCount(1)->and(($this->all)()[0]->rating)->toBe(4);
        expect(($this->props)('/aide/avis?ticket='.$ticket->reference())['already_sent'])->toBeTrue();
    });

    it('garde l\'avis du ticket même sans commentaire', function (): void {
        $ticket = ($this->open)();
        ($this->give)(['rating' => 2, 'ticket' => $ticket->reference()])->assertSessionHasNoErrors();

        expect(($this->all)()[0]->comment)->toBeNull();
    });
});

describe('invitations après un échange avec le support', function (): void {

    it('ne propose rien tant que l\'équipe n\'a pas répondu', function (): void {
        $ticket = ($this->open)();

        expect(($this->prompt)($ticket))->toBeNull();
    });

    it('propose après une réponse de l\'équipe, puis une fois le ticket résolu', function (): void {
        $ticket = ($this->open)();
        ($this->staff)($ticket);

        expect(($this->prompt)($ticket))->toBe(['key' => 'intervention:'.$ticket->id, 'context' => 'intervention']);

        ($this->staff)($ticket, 'resolved');
        expect(($this->prompt)($ticket))->toBe(['key' => 'ticket:'.$ticket->id, 'context' => 'ticket']);
    });

    it('propose aussi quand le client ferme lui-même son ticket, jamais obligatoire', function (): void {
        $ticket = ($this->open)();
        ($this->as)()->post('/aide/tickets/'.$ticket->reference().'/fermer')->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => SupportTicket::query()->findOrFail($ticket->id)->status))->toBe('closed')
            ->and(($this->prompt)($ticket)['context'])->toBe('ticket');

        // Aucun avis n'a été exigé pour fermer ni pour continuer à utiliser la plateforme.
        expect(($this->all)())->toBe([]);
        ($this->as)()->get('/tableau-de-bord')->assertOk();
    });

    it('ne se montre qu\'à l\'auteur du ticket', function (): void {
        $ticket = ($this->open)($this->colleague);
        ($this->staff)($ticket, 'resolved');

        expect(($this->prompt)($ticket, $this->colleague))->not->toBeNull()->and(($this->prompt)($ticket, $this->owner))->toBeNull();
    });

    it('disparaît une fois l\'avis donné', function (): void {
        $ticket = ($this->open)();
        ($this->staff)($ticket, 'resolved');
        ($this->give)(['rating' => 3, 'ticket' => $ticket->reference()]);

        expect(($this->prompt)($ticket))->toBeNull();
    });

    it('« Pas maintenant » écarte l\'invitation de ce moment, sans rien enregistrer comme avis', function (): void {
        $ticket = ($this->open)();
        ($this->staff)($ticket);
        $key = ($this->prompt)($ticket)['key'];

        ($this->as)()->post('/aide/avis/plus-tard', ['key' => $key])->assertSessionHasNoErrors();

        expect(($this->prompt)($ticket))->toBeNull()->and(($this->all)())->toBe([]);
        expect($this->asOwner(fn () => DB::table('feedback_prompt_dismissals')->where('prompt_key', $key)->count()))->toBe(1);

        // Refuser deux fois ne fait pas d'erreur ni de doublon.
        ($this->as)()->post('/aide/avis/plus-tard', ['key' => $key])->assertSessionHasNoErrors();
        expect($this->asOwner(fn () => DB::table('feedback_prompt_dismissals')->count()))->toBe(1);
    });

    it('ne repropose pas le même moment, mais laisse l\'avis possible plus tard depuis l\'aide', function (): void {
        $ticket = ($this->open)();
        ($this->staff)($ticket);
        ($this->as)()->post('/aide/avis/plus-tard', ['key' => ($this->prompt)($ticket)['key']]);

        // La page d'avis reste ouverte à tout moment, pour ce ticket aussi.
        ($this->as)()->get('/aide/avis?ticket='.$ticket->reference())->assertOk();
        ($this->give)(['rating' => 4, 'ticket' => $ticket->reference()])->assertSessionHasNoErrors();
        expect(($this->all)())->toHaveCount(1);
    });

    it('n\'interrompt pas deux fois pour le même moment : seul un nouveau moment (ticket résolu) peut reproposer, une fois', function (): void {
        $ticket = ($this->open)();
        ($this->staff)($ticket);
        ($this->as)()->post('/aide/avis/plus-tard', ['key' => ($this->prompt)($ticket)['key']]);

        ($this->staff)($ticket, 'resolved');
        $second = ($this->prompt)($ticket);
        expect($second['context'])->toBe('ticket');

        ($this->as)()->post('/aide/avis/plus-tard', ['key' => $second['key']]);
        expect(($this->prompt)($ticket))->toBeNull();
    });

    it('refuse une clé d\'invitation mal formée ou d\'un ticket qu\'on ne peut pas voir', function (): void {
        $theirs = ($this->open)($this->owner);

        ($this->as)()->post('/aide/avis/plus-tard', ['key' => 'n-importe-quoi'])->assertSessionHasErrors('key');
        ($this->as)($this->colleague)->post('/aide/avis/plus-tard', ['key' => 'ticket:'.$theirs->id])->assertNotFound();
        expect($this->asOwner(fn () => DB::table('feedback_prompt_dismissals')->count()))->toBe(0);
    });
});

describe('liens d\'avis publics', function (): void {
    it('montre les deux options, dans le même ordre, quelle que soit la note donnée', function (): void {
        $shown = [];

        foreach ([1, 3, 5] as $rating) {
            ($this->give)(['rating' => $rating])->assertSessionHasNoErrors();
            $shown[$rating] = ($this->props)('/aide/avis')['links'];
        }

        // Sans avoir rien donné non plus : les liens ne dépendent pas de la note ni de l'envoi d'un avis.
        $shown['aucun'] = ($this->props)('/aide/avis', $this->colleague)['links'];

        expect(collect($shown)->unique()->all())->toHaveCount(1)->and($shown[1])->toBe(['google', 'trustpilot']);
    });

    it('n\'expose que des liens configurés : jamais d\'adresse inventée ou écrite en dur', function (): void {
        config(['rendivoo.reviews.google' => null, 'rendivoo.reviews.trustpilot' => '']);

        expect(($this->props)('/aide/avis')['links'])->toBe([]);

        config(['rendivoo.reviews.trustpilot' => TRUSTPILOT]);
        expect(($this->props)('/aide/avis')['links'])->toBe(['trustpilot']);
        expect(json_encode(($this->props)('/aide/avis')))->not->toContain('trustpilot.com')->not->toContain('g.page');
    });

    it('ignore une adresse configurée qui n\'est pas en https ou n\'appartient pas à la plateforme', function (string $platform, string $url): void {
        config(["rendivoo.reviews.{$platform}" => $url]);

        expect(app(ReviewLinks::class)->url($platform))->toBeNull()->and(app(ReviewLinks::class)->available())->not->toContain($platform);
    })->with([
        ['google', 'http://g.page/r/nova/review'],
        ['google', 'https://evil.example/google.com'],
        ['google', 'https://google.com.evil.example/review'],
        ['trustpilot', 'https://trustpilot.evil.example/evaluate/nova'],
        ['trustpilot', 'javascript:alert(1)'],
        ['trustpilot', '//www.trustpilot.com/evaluate/nova'],
    ]);

    it('envoie vers la page configurée, telle quelle, après avoir noté l\'ouverture du lien', function (): void {
        ($this->give)(['rating' => 2, 'comment' => 'Pas convaincu']);
        $feedback = ($this->all)()[0];

        $response = ($this->as)()->get('/aide/avis/lien/trustpilot?feedback='.$feedback->id);

        $response->assertRedirect(TRUSTPILOT);
        $clicks = $this->asOwner(fn () => DB::table('review_link_clicks')->get());
        expect($clicks)->toHaveCount(1)->and($clicks[0]->platform)->toBe('trustpilot')->and($clicks[0]->feedback_id)->toBe($feedback->id)->and($clicks[0]->user_id)->toBe($this->owner->id);

        ($this->as)()->get('/aide/avis/lien/google')->assertRedirect(GOOGLE);
    });

    it('n\'ajoute rien à l\'adresse de destination : ni note, ni code, ni avantage', function (): void {
        ($this->give)(['rating' => 5]);
        $location = ($this->as)()->get('/aide/avis/lien/google?feedback='.($this->all)()[0]->id)->headers->get('Location');

        expect($location)->toBe(GOOGLE);
    });

    it('ne rattache pas un clic à l\'avis de quelqu\'un d\'autre', function (): void {
        ($this->give)(['rating' => 5]);
        $mine = ($this->all)()[0];

        ($this->as)($this->colleague)->get('/aide/avis/lien/google?feedback='.$mine->id)->assertRedirect(GOOGLE);

        expect($this->asOwner(fn () => DB::table('review_link_clicks')->value('feedback_id')))->toBeNull();
    });

    it('répond 404 pour une plateforme inconnue ou sans adresse configurée', function (): void {
        ($this->as)()->get('/aide/avis/lien/facebook')->assertNotFound();

        config(['rendivoo.reviews.google' => null]);
        ($this->as)()->get('/aide/avis/lien/google')->assertNotFound();
        expect($this->asOwner(fn () => DB::table('review_link_clicks')->count()))->toBe(0);
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/aide/avis/lien/google')->assertRedirect('/connexion');
    });

    it('garde l\'avis privé et les clics sur les liens publics dans deux tables séparées', function (): void {
        ($this->give)(['rating' => 1]);
        ($this->as)()->get('/aide/avis/lien/google');

        expect($this->asOwner(fn () => DB::table('nova_feedback')->count()))->toBe(1)
            ->and($this->asOwner(fn () => DB::table('review_link_clicks')->count()))->toBe(1)
            ->and(collect($this->asOwner(fn () => DB::select("select column_name from information_schema.columns where table_name = 'review_link_clicks'")))->pluck('column_name')->all())
            ->not->toContain('rating')->not->toContain('comment');
    });
});

describe('lecture par le Super Admin', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
        $this->admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->admin = $this->admin->refresh();
    });

    it('liste les avis privés avec note, commentaire, date, origine, ticket, compte et lien d\'avis public ouvert', function (): void {
        $ticket = ($this->open)();
        ($this->staff)($ticket, 'resolved');
        ($this->give)(['rating' => 2, 'comment' => 'Réponse trop lente', 'ticket' => $ticket->reference()]);
        ($this->give)(['rating' => 5, 'comment' => 'Excellent']);
        $first = collect(($this->all)())->firstWhere('rating', 2);
        ($this->as)()->get('/aide/avis/lien/google?feedback='.$first->id);

        $records = PlatformFeedback::query()->orderBy('created_at')->get();
        $component = Livewire::actingAs($this->admin, 'platform')->test(ListFeedback::class);

        $component->assertCanSeeTableRecords($records)->assertCountTableRecords(2)
            ->assertSee('Réponse trop lente')->assertSee('Excellent')->assertSee($ticket->reference())->assertSee('★★☆☆☆ 2/5')
            ->assertSee($this->org->name)->assertSee('Google')->assertSee('Ticket résolu ou fermé');
    });

    it('rappelle qu\'il s\'agit d\'avis privés, pas de notes Google ou Trustpilot, et calcule leur moyenne', function (): void {
        ($this->give)(['rating' => 2]);
        ($this->give)(['rating' => 4]);

        Livewire::actingAs($this->admin, 'platform')->test(ListFeedback::class)
            ->assertSee('ni des avis Google ni des avis Trustpilot')->assertSee('3.0 / 5');
    });

    it('filtre par note et par origine, et cherche dans les commentaires', function (): void {
        $ticket = ($this->open)();
        ($this->give)(['rating' => 1, 'comment' => 'Trop compliqué', 'ticket' => $ticket->reference()]);
        ($this->give)(['rating' => 5, 'comment' => 'Parfait']);

        $component = Livewire::actingAs($this->admin, 'platform')->test(ListFeedback::class);

        $component->filterTable('rating', 1)->assertSee('Trop compliqué')->assertDontSee('Parfait');
        $component->removeTableFilters()->filterTable('source', 'general')->assertSee('Parfait')->assertDontSee('Trop compliqué');
        $component->removeTableFilters()->searchTable('compliqué')->assertSee('Trop compliqué')->assertDontSee('Parfait');
    });

    it('est en lecture seule', function (): void {
        ($this->give)(['rating' => 3]);

        expect(fn () => DB::connection('pgsql_platform')->table('nova_feedback')->update(['rating' => 5]))->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->table('nova_feedback')->delete())->toThrow(QueryException::class);
        expect(FeedbackResource::canCreate())->toBeFalse();
    });

    it('n\'est pas accessible à un utilisateur client', function (): void {
        $this->actingAs($this->owner, 'web')->get('/admin/feedback')->assertRedirect('/admin/login');
    });

    it('ouvre la liste dans le panel', function (): void {
        $this->actingAs($this->admin, 'platform')->get('/admin/feedback')->assertOk();
    });
});

it('efface les avis d\'un établissement avec son compte', function (): void {
    ($this->give)(['rating' => 4]);
    ($this->as)()->get('/aide/avis/lien/google');

    $this->asOwner(function (): void {
        foreach (['review_link_clicks', 'feedback_prompt_dismissals', 'nova_feedback'] as $step) {
            DB::select('select gdpr_purge_org(?, ?)', [$this->org->id, $step]);
        }
    });

    expect($this->asOwner(fn () => DB::table('nova_feedback')->where('org_id', $this->org->id)->count()))->toBe(0)
        ->and($this->asOwner(fn () => DB::table('review_link_clicks')->where('org_id', $this->org->id)->count()))->toBe(0);
});
