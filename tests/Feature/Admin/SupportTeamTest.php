<?php

declare(strict_types=1);

use App\Modules\Admin\Exceptions\TeamRefused;
use App\Modules\Admin\Filament\Resources\SupportAgentResource;
use App\Modules\Admin\Filament\Resources\SupportAgentResource\Pages\ListSupportAgents;
use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages\ListSupportTickets;
use App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Notifications\SupportAgentInvitation;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Services\SupportTeam;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Admin\Support\SupportPermissions;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Suppression de tickets et équipe de support (docs/32) : suppression définitive (une et en lot) avec audit conservé, création et
 * invitation d'agents, statuts, permissions configurables, périmètre client, assignation et réaffectation.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    Storage::fake('local');
    Notification::fake();

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT, 'name' => 'Agnès Agent'])->save();
    $this->agent->refresh();
    $this->colleague = PlatformAdmin::query()->create(['email' => 'collegue@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
    $this->colleague->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT, 'name' => 'Colin Collègue'])->save();
    $this->colleague->refresh();

    $this->console = fn (): SupportConsole => app(SupportConsole::class);
    $this->team = fn (): SupportTeam => app(SupportTeam::class);
    $this->grant = function (PlatformAdmin $admin, array $permissions): PlatformAdmin {
        $admin->forceFill(['permissions' => SupportPermissions::normalise($permissions)])->save();

        return $admin->refresh();
    };
    $this->open = function (array $overrides = []): PlatformSupportTicket {
        $this->signedIn($this->owner, $this->org)->post('/aide/contact', [
            'subject' => 'Les rappels ne partent pas', 'category' => 'technical', 'related_to' => 'whatsapp', 'urgency' => 'medium',
            'description' => 'Depuis hier, aucun rappel WhatsApp n\'est envoyé.', 'email' => 'contact@example.com', 'token' => (string) Str::uuid(), ...$overrides,
        ])->assertSessionHasNoErrors();

        return PlatformSupportTicket::query()->latest('created_at')->latest('reference_number')->firstOrFail();
    };
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
    $this->rows = fn (string $table, string $ticketId) => DB::connection('pgsql_owner')->table($table)->where('ticket_id', $ticketId)->count();
});

describe('suppression d\'un ticket', function (): void {
    it('efface le ticket, ses messages, notes, pièces jointes et événements, mais garde la trace d\'audit', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->reply($this->boss, $ticket, 'Voir la capture.', null, [UploadedFile::fake()->image('capture.png', 40, 40)]);
        ($this->console)()->note($this->boss, $ticket->refresh(), 'Note interne confidentielle.');
        ($this->console)()->addTag($this->boss, $ticket->refresh(), 'urgent-client');
        $path = (string) DB::connection('pgsql_owner')->table('support_attachments')->value('path');
        Storage::disk('local')->assertExists($path);

        ($this->console)()->delete($this->boss, $ticket->id, 'Demande du client');

        expect(PlatformSupportTicket::query()->whereKey($ticket->id)->exists())->toBeFalse()
            ->and(DB::connection('pgsql_owner')->table('support_messages')->count())->toBe(0)
            ->and(DB::connection('pgsql_owner')->table('support_attachments')->count())->toBe(0)
            ->and(($this->rows)('support_ticket_events', $ticket->id))->toBe(0)
            ->and(($this->rows)('support_ticket_tags', $ticket->id))->toBe(0);
        Storage::disk('local')->assertMissing($path);

        $audit = ($this->audit)('support.ticket.deleted')->sole();
        $diff = json_decode((string) $audit->diff, true);

        expect($audit->actor_id)->toBe($this->boss->id)->and($audit->entity_id)->toBe($ticket->id)->and($audit->reason)->toBe('Demande du client')
            ->and($diff['reference'])->toBe($ticket->reference())->and($diff['subject'])->toBe('Les rappels ne partent pas')
            ->and($diff['internal_notes'])->toBe(1)->and($diff['attachments'])->toBe(1)->and($diff['messages'])->toBeGreaterThanOrEqual(3);
    });

    it('reste impossible de supprimer un message en dehors de la fonction de suppression', function (): void {
        $ticket = ($this->open)();

        expect(fn () => DB::connection('pgsql_platform')->table('support_messages')->where('ticket_id', $ticket->id)->delete())->toThrow(QueryException::class)
            ->and(fn () => DB::connection('pgsql_platform')->select('select support_delete_ticket(?)', [(string) Str::uuid()]))->not->toThrow(Throwable::class);
    });

    it('refuse le client, l\'agent sans permission et l\'agent hors périmètre', function (): void {
        $ticket = ($this->open)();

        expect(fn () => DB::connection('pgsql')->select('select support_delete_ticket(?)', [$ticket->id]))->toThrow(QueryException::class)
            ->and(fn () => ($this->console)()->delete($this->agent, $ticket->id))->toThrow(AuthorizationException::class);

        $allowed = ($this->grant)($this->agent, [SupportPermissions::DELETE_TICKETS => true]);
        ($this->console)()->assign($this->boss, $ticket, $this->colleague->id);

        // Assigné à un collègue : hors de la portée de l'agent, même avec la permission.
        expect(fn () => ($this->console)()->delete($allowed, $ticket->id))->toThrow(AuthorizationException::class)
            ->and(PlatformSupportTicket::query()->whereKey($ticket->id)->exists())->toBeTrue()
            ->and(($this->audit)('support.ticket.deleted'))->toHaveCount(0);
    });

    it('laisse un agent autorisé supprimer ses tickets', function (): void {
        $agent = ($this->grant)($this->agent, [SupportPermissions::DELETE_TICKETS => true]);
        $ticket = ($this->open)();

        ($this->console)()->delete($agent, $ticket->id);

        expect(PlatformSupportTicket::query()->whereKey($ticket->id)->exists())->toBeFalse()->and(($this->audit)('support.ticket.deleted')->sole()->actor_id)->toBe($agent->id);
    });

    it('demande de saisir la référence, redirige vers la boîte de réception et affiche une confirmation', function (): void {
        $ticket = ($this->open)();
        $page = fn () => Livewire::actingAs($this->boss, 'platform')->test(ViewSupportTicket::class, ['record' => $ticket->id]);

        $page()->callAction('delete', data: ['confirmation' => 'NWS-0000'])->assertHasActionErrors(['confirmation']);
        expect(PlatformSupportTicket::query()->whereKey($ticket->id)->exists())->toBeTrue();

        $page()->callAction('delete', data: ['confirmation' => $ticket->reference(), 'reason' => ''])->assertHasNoActionErrors()->assertRedirect(SupportTicketResource::getUrl('index'));
        expect(PlatformSupportTicket::query()->whereKey($ticket->id)->exists())->toBeFalse();
    });

    it('masque l\'action de suppression à un agent sans permission', function (): void {
        $ticket = ($this->open)();

        Livewire::actingAs($this->agent, 'platform')->test(ViewSupportTicket::class, ['record' => $ticket->id])->assertActionHidden('delete');
        Livewire::actingAs($this->boss, 'platform')->test(ViewSupportTicket::class, ['record' => $ticket->id])->assertActionVisible('delete');
    });
});

describe('suppression en lot', function (): void {
    it('supprime la sélection, audite chaque ticket et compte les refus', function (): void {
        $a = ($this->open)();
        $b = ($this->open)(['subject' => 'Facture en double']);
        $c = ($this->open)(['subject' => 'Autre sujet']);
        ($this->console)()->assign($this->boss, $c, $this->colleague->id);
        $agent = ($this->grant)($this->agent, [SupportPermissions::DELETE_TICKETS => true]);

        $result = ($this->console)()->bulk($agent, [$a->id, $b->id, $c->id], 'delete', fn (PlatformSupportTicket $t) => ($this->console)()->delete($agent, $t->id, 'Nettoyage'));

        expect($result)->toBe(['done' => 2, 'skipped' => 1])
            ->and(PlatformSupportTicket::query()->count())->toBe(1)
            ->and(($this->audit)('support.ticket.deleted'))->toHaveCount(2)
            ->and(($this->audit)('support.tickets.bulk'))->toHaveCount(1);
    });

    it('exige la case à cocher et le nombre exact de tickets, puis rafraîchit la liste', function (): void {
        $a = ($this->open)();
        $b = ($this->open)(['subject' => 'Facture en double']);
        $list = fn () => Livewire::actingAs($this->boss, 'platform')->test(ListSupportTickets::class)->set('activeTab', 'everything');

        $list()->callTableBulkAction('delete', [$a->id, $b->id], data: ['understood' => true, 'count' => '5'])->assertHasTableBulkActionErrors(['count']);
        $list()->callTableBulkAction('delete', [$a->id, $b->id], data: ['understood' => false, 'count' => '2'])->assertHasTableBulkActionErrors(['understood']);
        expect(PlatformSupportTicket::query()->count())->toBe(2);

        $list()->callTableBulkAction('delete', [$a->id, $b->id], data: ['understood' => true, 'count' => '2'])->assertHasNoTableBulkActionErrors();
        expect(PlatformSupportTicket::query()->count())->toBe(0);
    });

    it('masque la suppression en lot à un agent sans permission', function (): void {
        ($this->open)();

        Livewire::actingAs($this->agent, 'platform')->test(ListSupportTickets::class)->assertTableBulkActionHidden('delete');
        Livewire::actingAs($this->boss, 'platform')->test(ListSupportTickets::class)->assertTableBulkActionVisible('delete');
    });
});

describe('création et invitation d\'un agent', function (): void {
    it('crée un agent invité, envoie l\'invitation et consigne l\'audit', function (): void {
        $agent = ($this->team)()->create($this->boss, ['name' => 'Nina Nouvelle', 'email' => 'Nina@Rendivoo.test', 'phone' => '+32 470 00 00 00', 'internal_notes' => 'Équipe soir', 'permissions' => [SupportPermissions::MANAGE_TAGS => false]]);

        expect($agent)->status->toBe('invited')->support_role->toBe('agent')->email->toBe('nina@rendivoo.test')->name->toBe('Nina Nouvelle')
            ->and($agent->permissions)->toMatchArray([SupportPermissions::MANAGE_TAGS => false, SupportPermissions::DELETE_TICKETS => false])
            ->and($agent->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
        Notification::assertSentTo($agent, SupportAgentInvitation::class);
        expect(($this->audit)('support.agent.created'))->toHaveCount(1)->and(($this->audit)('support.agent.invited'))->toHaveCount(1);
    });

    it('active le compte avec le lien d\'invitation, une seule fois', function (): void {
        $agent = ($this->team)()->create($this->boss, ['name' => 'Nina Nouvelle', 'email' => 'nina@rendivoo.test']);
        $url = '';
        Notification::assertSentTo($agent, SupportAgentInvitation::class, function (SupportAgentInvitation $mail) use ($agent, &$url): bool {
            $url = (string) $mail->toMail($agent)->actionUrl;

            return true;
        });

        $this->get($url)->assertOk()->assertSee(__('team.invitation.submit'));
        $this->post($url, ['password' => 'Mot-de-passe-solide-2026!', 'password_confirmation' => 'trop-court'])->assertSessionHasErrors('password');
        $this->post($url, ['password' => 'Mot-de-passe-solide-2026!', 'password_confirmation' => 'Mot-de-passe-solide-2026!'])->assertRedirect();

        $agent->refresh();
        expect($agent->status)->toBe('active')->and($agent->invitation_token)->toBeNull()->and($agent->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
            ->and(($this->audit)('support.agent.activated'))->toHaveCount(1);

        // Le lien est à usage unique.
        $this->get($url)->assertOk()->assertSee(__('team.invitation.invalid'));
    });

    it('refuse un lien expiré, un lien falsifié et renvoyer l\'invitation invalide l\'ancien lien', function (): void {
        $agent = ($this->team)()->create($this->boss, ['name' => 'Nina Nouvelle', 'email' => 'nina@rendivoo.test']);
        $first = '';
        Notification::assertSentTo($agent, SupportAgentInvitation::class, function (SupportAgentInvitation $mail) use ($agent, &$first): bool {
            $first = (string) $mail->toMail($agent)->actionUrl;

            return true;
        });

        $token = Str::afterLast($first, '/');
        expect(($this->team)()->byToken($agent->id, $token))->not->toBeNull()->and(($this->team)()->byToken($agent->id, 'faux'))->toBeNull();

        ($this->team)()->resendInvitation($this->boss, $agent);
        expect(($this->team)()->byToken($agent->id, $token))->toBeNull()->and(($this->audit)('support.agent.invitation_resent'))->toHaveCount(1);

        $this->travel(SupportTeam::INVITATION_DAYS + 1)->days();
        $agent->refresh();
        expect($agent->invitation_expires_at->isPast())->toBeTrue();
    });

    it('refuse un e-mail déjà pris et un utilisateur qui n\'est pas Super Admin', function (): void {
        expect(fn () => ($this->team)()->create($this->boss, ['name' => 'Doublon', 'email' => 'agent@rendivoo.test']))->toThrow(TeamRefused::class)
            ->and(fn () => ($this->team)()->create($this->agent, ['name' => 'Pirate', 'email' => 'pirate@rendivoo.test']))->toThrow(TeamRefused::class)
            ->and(PlatformAdmin::query()->where('email', 'pirate@rendivoo.test')->exists())->toBeFalse();
    });

    it('envoie une réinitialisation du mot de passe à un agent actif', function (): void {
        ($this->team)()->sendPasswordReset($this->boss, $this->agent);

        Notification::assertSentTo($this->agent, SupportAgentInvitation::class);
        expect(($this->audit)('support.agent.password_reset_sent'))->toHaveCount(1);
        expect(fn () => ($this->team)()->resendInvitation($this->boss, $this->agent))->toThrow(TeamRefused::class);
    });

    it('crée un agent depuis l\'écran de l\'équipe et le liste avec sa charge', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);

        Livewire::actingAs($this->boss, 'platform')->test(ListSupportAgents::class)
            ->callAction('create', data: ['name' => 'Nina Nouvelle', 'email' => 'nina@rendivoo.test', 'permissions' => [SupportPermissions::MANAGE_TAGS]])->assertHasNoActionErrors()
            ->assertSee('Nina Nouvelle')->assertSee('Agnès Agent')->assertSee('Colin Collègue')
            ->assertCanSeeTableRecords(PlatformAdmin::query()->where('support_role', 'agent')->get());

        $row = SupportAgentResource::getEloquentQuery()->whereKey($this->agent->id)->firstOrFail();
        expect((int) $row->assigned_count)->toBe(1)->and((int) $row->open_count)->toBe(1)->and((int) $row->resolved_count)->toBe(0);
    });
});

describe('accès à l\'équipe', function (): void {
    it('réserve l\'écran de l\'équipe au Super Admin', function (): void {
        $this->actingAs($this->agent, 'platform');
        expect(SupportAgentResource::canAccess())->toBeFalse();

        $this->flushSession()->actingAs($this->boss, 'platform');
        expect(SupportAgentResource::canAccess())->toBeTrue();
    });

    it('ne gère jamais un Super Admin ni un éditeur de contenu', function (): void {
        expect(fn () => ($this->team)()->suspend($this->boss, $this->boss, null))->toThrow(TeamRefused::class)
            ->and(SupportAgentResource::getEloquentQuery()->pluck('platform_admins.id')->all())->not->toContain($this->boss->id);
    });

    it('refuse qu\'un agent modifie l\'équipe', function (): void {
        expect(fn () => ($this->team)()->update($this->agent, $this->colleague, ['name' => 'X']))->toThrow(TeamRefused::class)
            ->and(fn () => ($this->team)()->suspend($this->agent, $this->colleague, null))->toThrow(TeamRefused::class);
    });
});

describe('statuts : suspension, retrait, réactivation', function (): void {
    it('refuse la suspension tant que les tickets ouverts ne sont pas réaffectés', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);

        expect(fn () => ($this->team)()->suspend($this->boss, $this->agent, null))->toThrow(TeamRefused::class)
            ->and($this->agent->refresh()->status)->toBe('active');

        ($this->team)()->suspend($this->boss, $this->agent, $this->colleague->id, 'Congé');

        expect($this->agent->refresh())->status->toBe('suspended')
            ->and($this->agent->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
            ->and(PlatformSupportTicket::query()->find($ticket->id)->assigned_admin_id)->toBe($this->colleague->id);
        $audit = ($this->audit)('support.agent.suspended')->sole();
        expect($audit->reason)->toBe('Congé')->and(json_decode((string) $audit->diff, true)['tickets_reassigned'])->toBe(1);
    });

    it('remet les tickets dans la file à la demande et retire l\'agent', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);

        ($this->team)()->remove($this->boss, $this->agent, SupportTeam::QUEUE);

        expect($this->agent->refresh())->status->toBe('removed')->and(PlatformSupportTicket::query()->find($ticket->id)->assigned_admin_id)->toBeNull();
        expect(SupportAgentResource::getEloquentQuery()->where('platform_admins.status', '!=', 'removed')->pluck('platform_admins.id')->all())->not->toContain($this->agent->id);
    });

    it('refuse de réaffecter vers un agent inactif ou vers l\'agent lui-même', function (): void {
        $ticket = ($this->open)();
        ($this->console)()->assign($this->boss, $ticket, $this->agent->id);
        $this->colleague->forceFill(['status' => 'suspended'])->save();

        expect(fn () => ($this->team)()->suspend($this->boss, $this->agent, $this->colleague->id))->toThrow(TeamRefused::class)
            ->and(fn () => ($this->team)()->suspend($this->boss, $this->agent, $this->agent->id))->toThrow(TeamRefused::class)
            ->and($this->agent->refresh()->status)->toBe('active');
    });

    it('réactive un agent suspendu', function (): void {
        ($this->team)()->suspend($this->boss, $this->agent, null);
        ($this->team)()->reactivate($this->boss, $this->agent);

        expect($this->agent->refresh()->status)->toBe('active')->and(($this->audit)('support.agent.reactivated'))->toHaveCount(1);
    });

    it('coupe l\'accès d\'une session déjà ouverte dès la suspension', function (): void {
        $this->agent->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $this->flushSession()->actingAs($this->agent->refresh(), 'platform')->get('/admin/support-tickets')->assertOk();

        ($this->team)()->suspend($this->boss, $this->agent, null);

        $this->flushSession()->actingAs($this->agent->refresh(), 'platform')->get('/admin/support-tickets')->assertForbidden();
    });
});

describe('assignation', function (): void {
    it('n\'assigne pas à un agent invité, suspendu ou retiré', function (): void {
        $ticket = ($this->open)();
        $invited = ($this->team)()->create($this->boss, ['name' => 'Nina', 'email' => 'nina@rendivoo.test']);

        expect(array_keys(SupportAccess::assignees()))->toContain($this->agent->id)->not->toContain($invited->id)
            ->and(fn () => ($this->console)()->assign($this->boss, $ticket, $invited->id))->toThrow(AuthorizationException::class);

        $this->colleague->forceFill(['status' => 'suspended'])->save();
        expect(fn () => ($this->console)()->assign($this->boss, $ticket, $this->colleague->id))->toThrow(AuthorizationException::class);
    });

    it('laisse un agent prendre un ticket non assigné mais pas l\'attribuer à un collègue', function (): void {
        $ticket = ($this->open)();

        expect(fn () => ($this->console)()->assign($this->agent, $ticket, $this->colleague->id))->toThrow(AuthorizationException::class);
        ($this->console)()->assign($this->agent, $ticket, $this->agent->id);
        expect(PlatformSupportTicket::query()->find($ticket->id)->assigned_admin_id)->toBe($this->agent->id);

        $agent = ($this->grant)($this->agent, [SupportPermissions::ASSIGN_OTHERS => true]);
        ($this->console)()->assign($agent, PlatformSupportTicket::query()->find($ticket->id), $this->colleague->id);
        expect(PlatformSupportTicket::query()->find($ticket->id)->assigned_admin_id)->toBe($this->colleague->id);
        expect(($this->audit)('support.ticket.assigned'))->toHaveCount(2);
    });
});

describe('permissions configurables', function (): void {
    it('applique les permissions côté serveur : priorité, étiquettes et réouverture', function (): void {
        $ticket = ($this->open)();
        $agent = ($this->grant)($this->agent, [SupportPermissions::CHANGE_PRIORITY => false, SupportPermissions::MANAGE_TAGS => false, SupportPermissions::REOPEN_TICKETS => false]);

        expect(fn () => ($this->console)()->setPriority($agent, $ticket, 'high'))->toThrow(AuthorizationException::class)
            ->and(fn () => ($this->console)()->addTag($agent, $ticket, 'vip'))->toThrow(AuthorizationException::class);

        ($this->console)()->setStatus($agent, $ticket, 'resolved');
        expect(fn () => ($this->console)()->reopen($agent, PlatformSupportTicket::query()->find($ticket->id)))->toThrow(AuthorizationException::class);

        $agent = ($this->grant)($this->agent, []);
        ($this->console)()->setPriority($agent, $ticket, 'high');
        ($this->console)()->addTag($agent, $ticket, 'vip');
        ($this->console)()->reopen($agent, PlatformSupportTicket::query()->find($ticket->id));
        expect(PlatformSupportTicket::query()->find($ticket->id)->priority())->toBe('high');
    });

    it('laisse toujours un agent répondre, ajouter une note, changer l\'état et résoudre', function (): void {
        $ticket = ($this->open)();
        $agent = ($this->grant)($this->agent, [SupportPermissions::CHANGE_PRIORITY => false, SupportPermissions::MANAGE_TAGS => false]);

        ($this->console)()->reply($agent, $ticket, 'Nous regardons cela.', 'in_progress');
        ($this->console)()->note($agent, PlatformSupportTicket::query()->find($ticket->id), 'Piste : le numéro WhatsApp.');
        ($this->console)()->setStatus($agent, PlatformSupportTicket::query()->find($ticket->id), 'resolved');

        expect(PlatformSupportTicket::query()->find($ticket->id)->status)->toBe('resolved')
            ->and(($this->audit)('support.ticket.replied'))->toHaveCount(1)->and(($this->audit)('support.ticket.note_added'))->toHaveCount(1);
    });

    it('masque l\'action de priorité à un agent qui n\'en a pas le droit', function (): void {
        $ticket = ($this->open)();
        ($this->grant)($this->agent, [SupportPermissions::CHANGE_PRIORITY => false]);

        Livewire::actingAs($this->agent->refresh(), 'platform')->test(ViewSupportTicket::class, ['record' => $ticket->id])->assertActionHidden('priority');
    });

    it('enregistre la modification des permissions et du périmètre dans l\'audit', function (): void {
        $changes = ($this->team)()->update($this->boss, $this->agent, ['name' => 'Agnès A.', 'permissions' => [SupportPermissions::DELETE_TICKETS => true], 'scope' => [$this->org->id]]);

        expect($changes)->toHaveKeys(['name', 'permissions', 'scope'])->and(SupportPermissions::can($this->agent->refresh(), SupportPermissions::DELETE_TICKETS))->toBeTrue()
            ->and(($this->team)()->scopeOf($this->agent))->toBe([$this->org->id])->and(($this->audit)('support.agent.updated'))->toHaveCount(1);
    });
});

describe('périmètre client', function (): void {
    it('ne montre à un agent limité que les tickets de ses clients', function (): void {
        $mine = ($this->open)();
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount([], 'autre@example.com');
        $this->signedIn($otherOwner, $otherOrg)->post('/aide/contact', [
            'subject' => 'Autre client', 'category' => 'technical', 'related_to' => 'whatsapp', 'urgency' => 'medium',
            'description' => 'Un souci chez un autre client.', 'email' => 'autre@example.com', 'token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $theirs = PlatformSupportTicket::query()->where('org_id', $otherOrg->id)->firstOrFail();

        ($this->team)()->update($this->boss, $this->agent, ['scope' => [$this->org->id]]);

        $ids = SupportAccess::scope(PlatformSupportTicket::query(), $this->agent->refresh())->pluck('id')->all();
        expect($ids)->toContain($mine->id)->not->toContain($theirs->id)
            ->and(fn () => ($this->console)()->find($this->agent, $theirs->id))->toThrow(AuthorizationException::class)
            ->and(array_keys(SupportAccess::assignees($otherOrg->id)))->not->toContain($this->agent->id)->toContain($this->colleague->id)
            ->and(fn () => ($this->console)()->assign($this->boss, $theirs, $this->agent->id))->toThrow(AuthorizationException::class);
    });
});
