<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use Carbon\CarbonImmutable;

/**
 * Où en est la conversation d'un client : l'état (« waiting_service_choice »…), l'intention en cours et les éléments déjà choisis.
 */
final class ConversationState
{
    public const IDLE = 'idle';

    public string $state = self::IDLE;

    public ?string $intent = null;

    /** @var array<string, mixed> */
    public array $context = [];

    /** Dernier message entrant traité (un rejeu du même message ne fait pas avancer le dialogue deux fois). */
    public ?string $lastInbound = null;

    /** @var list<string> Réponses calculées mais pas encore toutes envoyées (reprise après un échec d'envoi). */
    public array $pending = [];

    public ?CarbonImmutable $handoffUntil = null;

    public function go(string $state, ?string $intent = null): void
    {
        $this->state = $state;
        $this->intent = $intent ?? $this->intent;
    }

    /** Retour au repos : plus aucun élément en cours. */
    public function reset(): void
    {
        $this->state = self::IDLE;
        $this->intent = null;
        $this->context = [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    public function string(string $key): ?string
    {
        $value = $this->context[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->context[$key]);
        }
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->context[$key] ?? [];

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'intent' => $this->intent,
            'context' => $this->context,
            'last_inbound' => $this->lastInbound,
            'pending' => $this->pending,
            'handoff_until' => $this->handoffUntil?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $state = new self;
        $state->state = is_string($data['state'] ?? null) ? $data['state'] : self::IDLE;
        $state->intent = is_string($data['intent'] ?? null) ? $data['intent'] : null;
        $state->context = is_array($data['context'] ?? null) ? $data['context'] : [];
        $state->lastInbound = is_string($data['last_inbound'] ?? null) ? $data['last_inbound'] : null;
        $state->pending = is_array($data['pending'] ?? null) ? array_values(array_map('strval', $data['pending'])) : [];
        $state->handoffUntil = is_string($data['handoff_until'] ?? null) ? CarbonImmutable::parse($data['handoff_until']) : null;

        return $state;
    }
}
