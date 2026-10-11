<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Modules\Legal\Support\LegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Réparation des acceptations juridiques des personnes invitées avant le correctif (docs/28) : leur compte a été créé après avoir coché
 * les conditions et la confidentialité (le serveur refusait la demande sinon), mais aucune ligne `user_consents` n'a été écrite.
 *
 * Règle d'or : on ne fabrique jamais une acceptation. Une acceptation n'est retrouvée que si le journal d'audit (`invitation.accepted`,
 * écrit dans la même transaction que le compte) prouve la date, et qu'une version publiée existait à cette date. Elle est alors écrite avec
 * la date, l'adresse IP et l'agent utilisateur de ce journal, et le contexte `invitation_recovered` qui dit d'où elle vient. Sans preuve,
 * la personne reste « à accepter » : l'avis de l'application lui demande d'accepter les versions en vigueur.
 *
 * Travaille avec le rôle propriétaire (lecture du journal de tous les établissements) : comme une migration, à lancer par l'administrateur.
 */
final class InviteeAcceptanceRepair
{
    private const CONNECTION = 'pgsql_owner';

    /**
     * Acceptations retrouvables et personnes laissées à une nouvelle acceptation.
     *
     * @return array{recoverable: list<array<string, mixed>>, unresolved: list<array{user_id: string, documents: list<string>, reason: string}>}
     */
    public function plan(): array
    {
        $db = DB::connection(self::CONNECTION);
        $required = LegalDocument::requiredFor(false);
        $recoverable = [];
        $unresolved = [];

        // Preuve : la première entrée « invitation.accepted » de chaque compte (son acteur est la personne qui vient d'être créée).
        $evidence = $db->table('audit_logs')->where('action', 'invitation.accepted')->where('actor_type', 'user')->whereNotNull('actor_id')
            ->orderBy('created_at')->get(['actor_id', 'org_id', 'ip', 'user_agent', 'created_at'])->unique('actor_id')->keyBy('actor_id');

        $users = $db->table('users')->get(['id', 'locale'])->keyBy('id');

        foreach ($users as $userId => $user) {
            $has = $db->table('user_consents')->where('user_id', $userId)->whereIn('type', $required)->pluck('type')->all();
            $missing = array_values(array_diff($required, $has));

            // Un propriétaire s'inscrit avec ses acceptations : le correctif ne concerne que les personnes invitées.
            if ($missing === [] || $this->isOwnerOnly((string) $userId)) {
                continue;
            }

            $proof = $evidence->get($userId);

            if ($proof === null) {
                $unresolved[] = ['user_id' => (string) $userId, 'documents' => $missing, 'reason' => 'no_evidence'];

                continue;
            }

            $at = CarbonImmutable::parse((string) $proof->created_at);

            foreach ($missing as $code) {
                $version = $this->versionInForceAt($code, $at);

                if ($version === null) {
                    $unresolved[] = ['user_id' => (string) $userId, 'documents' => [$code], 'reason' => 'no_version_at_that_date'];

                    continue;
                }

                $recoverable[] = [
                    'user_id' => (string) $userId, 'org_id' => $proof->org_id, 'type' => $code, 'document_version' => $version, 'granted_at' => $at->toIso8601String(),
                    'ip' => $proof->ip, 'user_agent' => $proof->user_agent, 'locale' => $user->locale === null ? null : substr((string) $user->locale, 0, 2),
                ];
            }
        }

        return ['recoverable' => $recoverable, 'unresolved' => $unresolved];
    }

    /**
     * Écrit les acceptations retrouvables (idempotent : une version déjà acceptée n'est jamais dupliquée).
     *
     * @return int nombre d'acceptations écrites
     */
    public function apply(): int
    {
        $db = DB::connection(self::CONNECTION);
        $written = 0;

        foreach ($this->plan()['recoverable'] as $row) {
            $inserted = $db->table('user_consents')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'user_id' => $row['user_id'], 'type' => $row['type'], 'document_version' => $row['document_version'],
                'granted_at' => $row['granted_at'], 'source' => LegalDocument::INVITATION_RECOVERED, 'ip' => $row['ip'],
                'user_agent' => $row['user_agent'] === null ? null : mb_substr((string) $row['user_agent'], 0, 255), 'locale' => $row['locale'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                $written++;
                $db->table('audit_logs')->insert([
                    'id' => (string) Str::uuid7(), 'org_id' => $row['org_id'], 'actor_type' => 'system', 'action' => 'legal.acceptance_recovered', 'entity_type' => 'user', 'entity_id' => $row['user_id'],
                    'diff' => json_encode(['document' => $row['type'], 'version' => $row['document_version'], 'evidence' => 'audit:invitation.accepted', 'accepted_at' => $row['granted_at']], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
            }
        }

        return $written;
    }

    private function isOwnerOnly(string $userId): bool
    {
        $roles = DB::connection(self::CONNECTION)->table('org_members')->where('user_id', $userId)->pluck('role');

        return $roles->isEmpty() || $roles->every(fn ($role): bool => $role === 'owner');
    }

    /** Version publiée en vigueur à cette date : la dernière dont la date d'effet et la publication précèdent l'acceptation. */
    private function versionInForceAt(string $code, CarbonImmutable $at): ?string
    {
        $version = DB::connection(self::CONNECTION)->table('legal_document_versions')->where('document', $code)->where('status', 'published')
            ->whereDate('effective_on', '<=', $at->toDateString())->where('published_at', '<=', $at)
            ->orderByDesc('effective_on')->orderByDesc('published_at')->value('version');

        return $version === null ? null : (string) $version;
    }
}
