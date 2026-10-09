<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Modules\Audit\Audit;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use App\Modules\Loyalty\Support\LoyaltyMessages;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Configuration du programme et cycle de vie (docs/27) : brouillon → actif ⇄ en pause → archivé.
 *
 * Règles :
 * - on n'active qu'un programme valide (nom, récompense et conditions renseignés, valeurs cohérentes, services choisis si « certains ») ;
 * - une modification « matérielle » (nombre de visites, services qui comptent, montant minimum, paiement exigé, récompense, règles
 *   d'utilisation, expiration) d'un programme qui a déjà des tampons ou des récompenses est d'abord PRÉSENTÉE (effet sur les clients)
 *   et n'est appliquée qu'après confirmation ; rien n'est remis à zéro en silence ;
 * - les récompenses déjà obtenues gardent ce qui a été promis (instantané) ; les tampons gagnés sont conservés ;
 * - baisser le nombre de visites attribue aussitôt leur récompense aux clients qui ont déjà assez de tampons ; l'augmenter ne retire rien ;
 * - en pause ou archivé : plus aucun tampon ; les récompenses déjà obtenues restent utilisables jusqu'à leur expiration ;
 * - chaque changement est inscrit au journal de fidélité et à l'audit.
 */
final class LoyaltyPrograms
{
    /** Champs qui changent ce que les clients gagnent ou obtiennent. */
    public const MATERIAL = [
        'required_visits', 'services_mode', 'earn_services', 'min_amount_cents', 'require_payment', 'auto_enroll',
        'reward_type', 'reward_value', 'reward_max_cents', 'reward_services_mode', 'reward_services',
        'redeem_timing', 'combinable', 'requires_appointment', 'expiry_days',
    ];

    public function __construct(private readonly LoyaltyEngine $engine) {}

    /**
     * Valeurs d'un nouveau programme : une règle simple et courante, à adapter.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'name' => ['fr' => '', 'nl' => '', 'en' => ''],
            'reward_description' => ['fr' => '', 'nl' => '', 'en' => ''],
            'terms' => ['fr' => '', 'nl' => '', 'en' => ''],
            'required_visits' => 8,
            'services_mode' => 'all',
            'earn_services' => [],
            'min_amount_cents' => null,
            'require_payment' => false,
            'auto_enroll' => true,
            'reward_type' => 'free_service',
            'reward_value' => null,
            'reward_max_cents' => null,
            'reward_services_mode' => 'all',
            'reward_services' => [],
            'redeem_timing' => 'next_visit',
            'combinable' => false,
            'requires_appointment' => true,
            'expiry_days' => null,
            'expiry_reminder_days' => 14,
        ];
    }

    /**
     * Valeurs actuelles d'un programme sous la forme du formulaire.
     *
     * @return array<string, mixed>
     */
    public function values(LoyaltyProgram $program): array
    {
        return [
            'name' => $this->texts($program->name),
            'reward_description' => $this->texts($program->reward_description),
            'terms' => $this->texts($program->terms),
            'required_visits' => $program->required_visits,
            'services_mode' => $program->services_mode,
            'earn_services' => $program->serviceIds('earn') ?? [],
            'min_amount_cents' => $program->min_amount_cents,
            'require_payment' => $program->require_payment,
            'auto_enroll' => $program->auto_enroll,
            'reward_type' => $program->reward_type,
            'reward_value' => $program->reward_value,
            'reward_max_cents' => $program->reward_max_cents,
            'reward_services_mode' => $program->reward_services_mode,
            'reward_services' => $program->serviceIds('reward') ?? [],
            'redeem_timing' => $program->redeem_timing,
            'combinable' => $program->combinable,
            'requires_appointment' => $program->requires_appointment,
            'expiry_days' => $program->expiry_days,
            'expiry_reminder_days' => $program->expiry_reminder_days,
        ];
    }

    /**
     * Enregistre le programme (brouillon ou en cours). Retourne `saved` ou, si une modification matérielle touche des clients qui ont déjà
     * progressé et n'est pas confirmée, `confirm` avec l'effet attendu : rien n'est alors écrit.
     *
     * @param  array<string, mixed>  $input
     * @return array{result: 'saved'|'confirm', program: LoyaltyProgram|null, impact: array<string, mixed>|null}
     */
    public function save(array $input, string $actorId, bool $confirmed): array
    {
        $data = $this->normalise($input);
        $program = LoyaltyProgram::current();

        if ($program === null) {
            return DB::transaction(function () use ($data, $actorId): array {
                $created = LoyaltyProgram::query()->create([...$this->columns($data), 'org_id' => $this->orgId(), 'status' => LoyaltyProgram::DRAFT, 'created_by' => $actorId]);
                $this->syncServices($created, $data);
                $this->engine->record($created, null, LoyaltyLedgerEntry::CONFIG_CHANGED, 0, null, actorId: $actorId, data: ['created' => true]);
                Audit::record('loyalty.program_created', 'user', $actorId, $created->org_id, 'loyalty_programs', $created->id);

                return ['result' => 'saved', 'program' => $created, 'impact' => null];
            });
        }

        $before = $this->values($program);
        $changes = $this->changes($before, $data);

        if ($changes === []) {
            return ['result' => 'saved', 'program' => $program, 'impact' => null];
        }

        $material = array_values(array_intersect(array_keys($changes), self::MATERIAL));

        if ($material !== [] && $program->status !== LoyaltyProgram::DRAFT && $this->hasProgress($program) && ! $confirmed) {
            return ['result' => 'confirm', 'program' => $program, 'impact' => $this->impact($program, $data, $material)];
        }

        DB::transaction(function () use ($program, $data, $changes, $actorId): void {
            $lowered = $data['required_visits'] < $program->required_visits;
            $program->forceFill($this->columns($data))->save();
            $this->syncServices($program, $data);
            $this->engine->record($program, null, LoyaltyLedgerEntry::CONFIG_CHANGED, 0, null, actorId: $actorId, data: ['changes' => $changes]);
            Audit::record('loyalty.program_updated', 'user', $actorId, $program->org_id, 'loyalty_programs', $program->id, ['fields' => array_keys($changes)]);

            if ($lowered && $program->isEarning()) {
                $this->engine->issueAllDue($program);
            }
        });

        return ['result' => 'saved', 'program' => $program->refresh(), 'impact' => null];
    }

    /**
     * Ce qui manque pour activer (liste de codes), vide quand le programme est valide.
     *
     * @return list<string>
     */
    public function problems(LoyaltyProgram $program): array
    {
        $problems = [];

        foreach (['name', 'reward_description', 'terms'] as $field) {
            if ($program->text($field, 'fr') === '') {
                $problems[] = $field;
            }
        }

        if ($program->required_visits < 1 || $program->required_visits > 100) {
            $problems[] = 'required_visits';
        }

        $value = $program->reward_value;

        if (($program->reward_type === 'percent_discount' && ($value === null || $value < 1 || $value > 100)) || ($program->reward_type === 'fixed_amount' && ($value === null || $value < 1))) {
            $problems[] = 'reward_value';
        }

        if ($program->services_mode === 'selected' && ($program->serviceIds('earn') ?? []) === []) {
            $problems[] = 'earn_services';
        }

        if ($program->reward_services_mode === 'selected' && ($program->serviceIds('reward') ?? []) === []) {
            $problems[] = 'reward_services';
        }

        return $problems;
    }

    /**
     * Langues où le nom, la récompense ou les conditions manquent : les clients de cette langue verront le texte d'une autre langue.
     *
     * @return list<string>
     */
    public function incompleteLanguages(LoyaltyProgram $program): array
    {
        return array_values(array_filter((array) config('nova.locales'), function (string $locale) use ($program): bool {
            foreach (['name', 'reward_description', 'terms'] as $field) {
                if (trim((string) ($program->{$field}[$locale] ?? '')) === '') {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @throws InvalidArgumentException `no_program`, `invalid` (programme incomplet) ou `bad_state`
     */
    public function activate(string $actorId): LoyaltyProgram
    {
        $program = LoyaltyProgram::current() ?? throw new InvalidArgumentException('no_program');

        if (! in_array($program->status, [LoyaltyProgram::DRAFT, LoyaltyProgram::PAUSED], true)) {
            throw new InvalidArgumentException('bad_state');
        }

        if ($this->problems($program) !== []) {
            throw new InvalidArgumentException('invalid');
        }

        $this->transition($program, LoyaltyProgram::ACTIVE, $actorId);
        $this->engine->issueAllDue($program->refresh());

        return $program;
    }

    public function pause(string $actorId): LoyaltyProgram
    {
        $program = LoyaltyProgram::current() ?? throw new InvalidArgumentException('no_program');

        if ($program->status !== LoyaltyProgram::ACTIVE) {
            throw new InvalidArgumentException('bad_state');
        }

        return $this->transition($program, LoyaltyProgram::PAUSED, $actorId);
    }

    public function archive(string $actorId): LoyaltyProgram
    {
        $program = LoyaltyProgram::current() ?? throw new InvalidArgumentException('no_program');

        return $this->transition($program, LoyaltyProgram::ARCHIVED, $actorId);
    }

    /**
     * Réglages des messages WhatsApp (activation et texte personnalisé par langue). Les variables inconnues sont refusées.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws InvalidArgumentException `no_program` ou `unknown_variable:{kind}:{nom}`
     */
    public function saveMessages(array $input, string $actorId): LoyaltyProgram
    {
        $program = LoyaltyProgram::current() ?? throw new InvalidArgumentException('no_program');
        $settings = LoyaltyMessages::defaults();

        foreach (LoyaltyMessages::KINDS as $kind) {
            $settings[$kind]['enabled'] = (bool) ($input[$kind]['enabled'] ?? false);

            foreach ((array) config('nova.locales') as $locale) {
                $text = trim((string) ($input[$kind]['texts'][$locale] ?? ''));
                $unknown = LoyaltyMessages::unknownVariables($text);

                if ($unknown !== []) {
                    throw new InvalidArgumentException("unknown_variable:{$kind}:{$unknown[0]}");
                }

                $settings[$kind]['texts'][$locale] = mb_substr($text, 0, LoyaltyMessages::MAX_LENGTH);
            }
        }

        $enabled = array_keys(array_filter($settings, fn (array $row): bool => $row['enabled']));
        $before = array_keys(array_filter((array) $program->messages, fn ($row): bool => (bool) ($row['enabled'] ?? false)));
        $program->forceFill(['messages' => $settings])->save();
        $this->engine->record($program, null, LoyaltyLedgerEntry::CONFIG_CHANGED, 0, null, actorId: $actorId, data: ['messages' => ['enabled' => $enabled, 'was' => $before]]);
        Audit::record('loyalty.messages_updated', 'user', $actorId, $program->org_id, 'loyalty_programs', $program->id, ['enabled' => $enabled]);

        return $program;
    }

    /**
     * Effet d'une modification matérielle sur les clients actuels.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $material
     * @return array<string, mixed>
     */
    public function impact(LoyaltyProgram $program, array $data, array $material): array
    {
        $members = LoyaltyMember::query()->where('program_id', $program->id)->where('status', LoyaltyMember::ACTIVE);
        $required = $data['required_visits'];

        return [
            'fields' => $material,
            'members' => (clone $members)->count(),
            'in_progress' => (clone $members)->where('stamps', '>', 0)->count(),
            'required_from' => $program->required_visits,
            'required_to' => $required,
            // Clients qui ont déjà assez de tampons pour la nouvelle règle : leur récompense est attribuée tout de suite (programme actif).
            'rewards_now' => $required < $program->required_visits ? (clone $members)->where('stamps', '>=', $required)->count() : 0,
            // Plus de visites à faire pour certains : leur progression est conservée, seule la cible change.
            'further_away' => $required > $program->required_visits ? (clone $members)->where('stamps', '>', 0)->count() : 0,
            'available_rewards' => LoyaltyReward::query()->where('program_id', $program->id)->where('status', LoyaltyReward::AVAILABLE)->count(),
            'reward_changed' => array_intersect($material, ['reward_type', 'reward_value', 'reward_max_cents', 'reward_services_mode', 'reward_services', 'redeem_timing', 'combinable', 'requires_appointment', 'expiry_days']) !== [],
            'earn_rule_changed' => array_intersect($material, ['services_mode', 'earn_services', 'min_amount_cents', 'require_payment', 'auto_enroll']) !== [],
            'active' => $program->isEarning(),
        ];
    }

    /** Le programme a-t-il déjà des participants ou des récompenses ? */
    public function hasProgress(LoyaltyProgram $program): bool
    {
        return LoyaltyMember::query()->where('program_id', $program->id)->exists() || LoyaltyReward::query()->where('program_id', $program->id)->exists();
    }

    private function transition(LoyaltyProgram $program, string $to, string $actorId): LoyaltyProgram
    {
        $from = $program->status;

        if ($from === $to) {
            return $program;
        }

        DB::transaction(function () use ($program, $from, $to, $actorId): void {
            $now = CarbonImmutable::now();
            $program->forceFill([
                'status' => $to,
                'activated_at' => $to === LoyaltyProgram::ACTIVE ? ($program->activated_at ?? $now) : $program->activated_at,
                'paused_at' => $to === LoyaltyProgram::PAUSED ? $now : null,
                'archived_at' => $to === LoyaltyProgram::ARCHIVED ? $now : null,
            ])->save();
            $this->engine->record($program, null, LoyaltyLedgerEntry::STATUS_CHANGED, 0, null, actorId: $actorId, data: ['from' => $from, 'to' => $to]);
            Audit::record("loyalty.program_{$to}", 'user', $actorId, $program->org_id, 'loyalty_programs', $program->id, ['from' => $from]);
        });

        return $program;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalise(array $input): array
    {
        $data = [...self::defaults(), ...array_intersect_key($input, self::defaults())];

        foreach (['name', 'reward_description', 'terms'] as $field) {
            $data[$field] = $this->texts((array) $data[$field]);
        }

        foreach (['earn_services', 'reward_services'] as $field) {
            $data[$field] = array_values(array_unique(array_map('strval', (array) $data[$field])));
        }

        $type = (string) $data['reward_type'];

        return [
            ...$data,
            'required_visits' => (int) $data['required_visits'],
            'min_amount_cents' => $data['min_amount_cents'] === null || $data['min_amount_cents'] === '' ? null : (int) $data['min_amount_cents'],
            'reward_value' => in_array($type, ['percent_discount', 'fixed_amount'], true) && $data['reward_value'] !== null && $data['reward_value'] !== '' ? (int) $data['reward_value'] : null,
            'reward_max_cents' => in_array($type, ['free_service', 'percent_discount', 'fixed_amount'], true) && $data['reward_max_cents'] !== null && $data['reward_max_cents'] !== '' ? (int) $data['reward_max_cents'] : null,
            'expiry_days' => $data['expiry_days'] === null || $data['expiry_days'] === '' ? null : (int) $data['expiry_days'],
            'expiry_reminder_days' => (int) ($data['expiry_reminder_days'] ?: 14),
            'require_payment' => (bool) $data['require_payment'],
            'auto_enroll' => (bool) $data['auto_enroll'],
            'combinable' => (bool) $data['combinable'],
            'requires_appointment' => (bool) $data['requires_appointment'],
            'earn_services' => $data['services_mode'] === 'selected' ? $data['earn_services'] : [],
            'reward_services' => $data['reward_services_mode'] === 'selected' ? $data['reward_services'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function columns(array $data): array
    {
        return array_diff_key($data, ['earn_services' => 1, 'reward_services' => 1]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncServices(LoyaltyProgram $program, array $data): void
    {
        DB::table('loyalty_program_services')->where('program_id', $program->id)->delete();

        foreach (['earn' => 'earn_services', 'reward' => 'reward_services'] as $kind => $field) {
            $ids = DB::table('services')->whereIn('id', $data[$field])->pluck('id')->all();

            foreach ($ids as $id) {
                DB::table('loyalty_program_services')->insert(['org_id' => $program->org_id, 'program_id' => $program->id, 'service_id' => $id, 'kind' => $kind]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($before as $field => $value) {
            $new = $after[$field] ?? null;
            $left = is_array($value) ? $this->sorted($value) : $value;
            $right = is_array($new) ? $this->sorted($new) : $new;

            if ($left !== $right) {
                $changes[$field] = ['from' => $value, 'to' => $new];
            }
        }

        return $changes;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return array<int|string, mixed>
     */
    private function sorted(array $values): array
    {
        if (array_is_list($values)) {
            sort($values);

            return $values;
        }

        ksort($values);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $texts
     * @return array<string, string>
     */
    private function texts(array $texts): array
    {
        $clean = [];

        foreach ((array) config('nova.locales') as $locale) {
            $clean[$locale] = mb_substr(trim((string) ($texts[$locale] ?? '')), 0, 2000);
        }

        return $clean;
    }

    private function orgId(): string
    {
        $organization = app(CurrentTenant::class)->organization();

        return $organization !== null ? $organization->id : (string) Organization::query()->value('id');
    }
}
