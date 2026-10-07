<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Mémoire des conversations : dans le cache (Redis en production), chiffrée, avec une durée de vie.
 *
 * Pas de table : l'état d'une conversation est éphémère (une session de réservation), il contient un nom et un e-mail
 * saisis par le client, et il disparaît tout seul. Aucune donnée à purger lors d'un effacement RGPD.
 * La clé contient l'organisation : aucune fuite possible entre établissements.
 */
final class ConversationStore
{
    /**
     * Exécute un tour de dialogue sous verrou (deux messages rapprochés du même client ne se croisent pas), puis enregistre l'état.
     *
     * @template T
     *
     * @param  callable(ConversationState): T  $turn
     * @return T
     */
    public function locked(string $orgId, string $customerId, callable $turn): mixed
    {
        return Cache::lock($this->key($orgId, $customerId).':lock', 30)->block(15, function () use ($orgId, $customerId, $turn): mixed {
            $state = $this->load($orgId, $customerId);
            $result = $turn($state);
            $this->save($orgId, $customerId, $state);

            return $result;
        });
    }

    public function load(string $orgId, string $customerId): ConversationState
    {
        $payload = Cache::get($this->key($orgId, $customerId));

        if (! is_string($payload)) {
            return new ConversationState;
        }

        try {
            $data = json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new ConversationState; // contenu illisible (clé changée, cache corrompu) : on repart de zéro
        }

        return is_array($data) ? ConversationState::fromArray($data) : new ConversationState;
    }

    public function save(string $orgId, string $customerId, ConversationState $state): void
    {
        $minutes = (int) config('conversation.session_ttl_minutes');

        if ($state->handoffUntil !== null && $state->handoffUntil > CarbonImmutable::now()) {
            $minutes = max($minutes, (int) config('conversation.handoff_hours') * 60);
        }

        Cache::put($this->key($orgId, $customerId), Crypt::encryptString(json_encode($state->toArray(), JSON_THROW_ON_ERROR)), now()->addMinutes($minutes));
    }

    private function key(string $orgId, string $customerId): string
    {
        return 'conversation:'.$orgId.':'.$customerId;
    }
}
