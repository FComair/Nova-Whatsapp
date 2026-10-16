<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformMessageTemplate;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Whatsapp\Models\MessageTemplateTranslation;
use App\Modules\Whatsapp\Support\MessageTemplateStatus;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use App\Modules\Whatsapp\Support\MessageVariables;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Bibliothèque de messages WhatsApp du Super Admin (docs/39) : création, modification, duplication, activation, archivage, suppression
 * et circuit d'approbation, secteur par secteur. Chaque opération vérifie la permission, s'exécute dans une transaction, écrit dans
 * le journal en ajout seul du modèle (`message_template_events`) et dans le journal d'audit de la plateforme.
 *
 * Règle centrale : seul un modèle APPROUVÉ et ACTIVÉ peut servir à un vrai message. Modifier le texte ou le type d'un modèle en
 * attente ou approuvé le ramène en brouillon : il doit être soumis et approuvé de nouveau.
 */
final class MessageTemplateManager
{
    private const PERMISSION = 'settings.edit';

    /**
     * @param  array{sector_code: string, type: string, name: string, category?: string|null, is_enabled?: bool, translations: array<string, string|null>}  $data
     */
    public function create(array $data, string $adminId): PlatformMessageTemplate
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $translations = $this->cleanTranslations($data['translations']);
        $this->assertSector($data['sector_code']);
        $this->assertType($data['type']);

        return $this->db()->transaction(function () use ($data, $translations, $adminId): PlatformMessageTemplate {
            $name = trim($data['name']);
            $slug = $this->newSlug($data['sector_code'], $data['type'] === MessageTemplateType::CUSTOM ? $name : $data['type']);

            $template = PlatformMessageTemplate::query()->create([
                'sector_code' => $data['sector_code'],
                'type' => $data['type'],
                'slug' => $slug,
                'name' => $name,
                'meta_name' => $this->metaName($data['sector_code'], $slug),
                'category' => $this->category($data['type'], $data['category'] ?? null),
                'status' => MessageTemplateStatus::DRAFT,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'created_by' => $adminId,
            ]);
            $this->replaceTranslations($template, $translations);
            $this->log($template, 'created', null, MessageTemplateStatus::DRAFT, $adminId);

            return $template;
        });
    }

    /**
     * @param  array{type?: string, name?: string, category?: string|null, is_enabled?: bool, translations?: array<string, string|null>}  $data
     */
    public function update(PlatformMessageTemplate $template, array $data, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $translations = array_key_exists('translations', $data) ? $this->cleanTranslations($data['translations']) : null;

        if (isset($data['type'])) {
            $this->assertType($data['type']);
        }

        $this->db()->transaction(function () use ($template, $data, $translations, $adminId): void {
            $from = $template->status;
            $template->fill(array_filter([
                'name' => isset($data['name']) ? trim($data['name']) : null,
                'type' => $data['type'] ?? null,
            ], fn (mixed $value): bool => $value !== null));

            if (isset($data['type']) || array_key_exists('category', $data)) {
                $template->category = $this->category($template->type, $data['category'] ?? null);
            }

            $contentChanged = $translations !== null && $translations !== $this->bodies($template);
            $typeChanged = $template->isDirty('type');
            $changed = $template->isDirty(['name', 'type', 'category']) || $contentChanged;

            if (($contentChanged || $typeChanged) && in_array($from, [MessageTemplateStatus::PENDING, MessageTemplateStatus::APPROVED], true)) {
                $template->status = MessageTemplateStatus::DRAFT;
                $template->rejection_reason = null;
            }

            if (! $changed) {
                return;
            }

            $template->save();

            if ($contentChanged && $translations !== null) {
                $this->replaceTranslations($template, $translations);
            }

            $this->log($template, 'updated', $from, $template->status, $adminId);
        });
    }

    /** Copie indépendante (brouillon) dans le même secteur ou dans un autre ; le secteur d'origine n'est pas affecté. */
    public function duplicate(PlatformMessageTemplate $template, ?string $sectorCode, string $adminId): PlatformMessageTemplate
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $target = $sectorCode ?? $template->sector_code;
        $this->assertSector($target);

        return $this->db()->transaction(function () use ($template, $target, $adminId): PlatformMessageTemplate {
            $copy = $this->create([
                'sector_code' => $target,
                'type' => $template->type,
                'name' => $target === $template->sector_code ? $template->name.' '.__('message_templates.copy_suffix') : $template->name,
                'category' => $template->category,
                'is_enabled' => $template->is_enabled,
                'translations' => $this->bodies($template),
            ], $adminId);

            $this->log($copy, 'duplicated', null, MessageTemplateStatus::DRAFT, $adminId, extra: ['from_template' => $template->id, 'from_sector' => $template->sector_code]);

            return $copy;
        });
    }

    /** Brouillon ou refusé → en attente d'approbation. Le texte de chaque langue doit être valide. */
    public function submit(PlatformMessageTemplate $template, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $this->requireStatus($template, [MessageTemplateStatus::DRAFT, MessageTemplateStatus::REJECTED], 'submit');

        $bodies = $this->bodies($template);

        if ($bodies === []) {
            throw new DomainException(__('message_templates.errors.no_translation'));
        }

        foreach ($bodies as $language => $body) {
            $problems = MessageVariables::problems($body);

            if ($problems !== []) {
                throw new DomainException(__('message_templates.errors.invalid_text', ['language' => $language, 'problem' => $this->describe($problems[0])]));
            }
        }

        $this->transition($template, MessageTemplateStatus::PENDING, 'submitted', $adminId, ['submitted_at' => now(), 'rejection_reason' => null]);
    }

    public function approve(PlatformMessageTemplate $template, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $this->requireStatus($template, [MessageTemplateStatus::PENDING], 'approve');
        $this->transition($template, MessageTemplateStatus::APPROVED, 'approved', $adminId, ['decided_by' => $adminId, 'decided_at' => now(), 'rejection_reason' => null]);
    }

    public function reject(PlatformMessageTemplate $template, string $reason, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $this->requireStatus($template, [MessageTemplateStatus::PENDING], 'reject');

        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('message_templates.errors.reason_required'));
        }

        $this->transition($template, MessageTemplateStatus::REJECTED, 'rejected', $adminId, ['decided_by' => $adminId, 'decided_at' => now(), 'rejection_reason' => trim($reason)], trim($reason));
    }

    public function archive(PlatformMessageTemplate $template, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $this->requireStatus($template, [MessageTemplateStatus::DRAFT, MessageTemplateStatus::PENDING, MessageTemplateStatus::APPROVED, MessageTemplateStatus::REJECTED], 'archive');
        $this->transition($template, MessageTemplateStatus::ARCHIVED, 'archived', $adminId);
    }

    /** Un modèle archivé revient en brouillon : il faut le soumettre et l'approuver de nouveau avant de l'utiliser. */
    public function restore(PlatformMessageTemplate $template, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);
        $this->requireStatus($template, [MessageTemplateStatus::ARCHIVED], 'restore');
        $this->transition($template, MessageTemplateStatus::DRAFT, 'restored', $adminId, ['rejection_reason' => null]);
    }

    public function setEnabled(PlatformMessageTemplate $template, bool $enabled, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        if ($template->is_enabled === $enabled) {
            return;
        }

        $this->db()->transaction(function () use ($template, $enabled, $adminId): void {
            $template->forceFill(['is_enabled' => $enabled])->save();
            $this->log($template, $enabled ? 'enabled' : 'disabled', $template->status, $template->status, $adminId);
        });
    }

    /** Suppression définitive d'un brouillon, d'un modèle refusé ou archivé ; son historique est conservé. */
    public function delete(PlatformMessageTemplate $template, string $adminId): void
    {
        AdminAccess::authorize($adminId, self::PERMISSION);

        if (! in_array($template->status, MessageTemplateStatus::DELETABLE, true)) {
            throw new DomainException(__('message_templates.errors.not_deletable'));
        }

        $this->db()->transaction(function () use ($template, $adminId): void {
            $this->log($template, 'deleted', $template->status, null, $adminId);
            $template->delete();
        });
    }

    /**
     * Problèmes de chaque langue (codes de MessageVariables::problems), pour le formulaire.
     *
     * @param  array<string, string|null>  $translations
     * @return array<string, list<string>>
     */
    public function problems(array $translations): array
    {
        $found = [];

        foreach ($translations as $language => $body) {
            if (filled($body) && ($problems = MessageVariables::problems((string) $body)) !== []) {
                $found[$language] = $problems;
            }
        }

        return $found;
    }

    /** Phrase lisible pour un code de problème. */
    public function describe(string $problem): string
    {
        [$code, $name] = array_pad(explode(':', $problem, 2), 2, '');

        return __('message_templates.problems.'.$code, ['name' => $name, 'max' => MessageVariables::MAX_LENGTH, 'count' => MessageVariables::MAX_VARIABLES]);
    }

    /**
     * @return array<string, string> langue => texte
     */
    public function bodies(PlatformMessageTemplate $template): array
    {
        return MessageTemplateTranslation::query()->where('template_id', $template->id)->orderBy('language')->pluck('body', 'language')->all();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function requireStatus(PlatformMessageTemplate $template, array $allowed, string $action): void
    {
        if (! in_array($template->status, $allowed, true)) {
            throw new DomainException(__('message_templates.errors.wrong_status', ['action' => __("message_templates.actions.{$action}"), 'status' => __("message_templates.statuses.{$template->status}")]));
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(PlatformMessageTemplate $template, string $to, string $action, string $adminId, array $attributes = [], ?string $reason = null): void
    {
        $this->db()->transaction(function () use ($template, $to, $action, $adminId, $attributes, $reason): void {
            $from = $template->status;
            $template->forceFill(['status' => $to, ...$attributes])->save();
            $this->log($template, $action, $from, $to, $adminId, $reason);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(PlatformMessageTemplate $template, string $action, ?string $from, ?string $to, string $adminId, ?string $reason = null, array $extra = []): void
    {
        $snapshot = [
            'name' => $template->name,
            'type' => $template->type,
            'category' => $template->category,
            'is_enabled' => $template->is_enabled,
            'translations' => $this->bodies($template),
        ] + $extra;

        $this->db()->table('message_template_events')->insert([
            'id' => (string) Str::uuid7(),
            'template_id' => $template->id,
            'sector_code' => $template->sector_code,
            'template_name' => $template->name,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'actor_id' => $adminId,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        PlatformAudit::record($adminId, 'whatsapp_template.'.$action, null, 'message_templates', $template->id, array_filter([
            'sector' => $template->sector_code, 'name' => $template->name, 'from' => $from, 'to' => $to,
        ], fn (mixed $value): bool => $value !== null), $reason);
    }

    /**
     * @param  array<string, string>  $translations
     */
    private function replaceTranslations(PlatformMessageTemplate $template, array $translations): void
    {
        $this->db()->table('message_template_translations')->where('template_id', $template->id)->whereNotIn('language', array_keys($translations))->delete();

        foreach ($translations as $language => $body) {
            $this->db()->table('message_template_translations')->upsert(
                [['id' => (string) Str::uuid7(), 'template_id' => $template->id, 'language' => $language, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]],
                ['template_id', 'language'],
                ['body', 'updated_at'],
            );
        }
    }

    /**
     * Garde les langues proposées par l'application et les textes non vides ; la validité des variables est contrôlée à la soumission,
     * pour qu'un brouillon puisse rester incomplet.
     *
     * @param  array<string, string|null>  $translations
     * @return array<string, string>
     */
    private function cleanTranslations(array $translations): array
    {
        $clean = [];

        foreach ((array) config('nova.locales') as $language) {
            $body = trim((string) ($translations[$language] ?? ''));

            if ($body !== '') {
                $clean[(string) $language] = $body;
            }
        }

        if ($clean === []) {
            throw new InvalidArgumentException(__('message_templates.errors.no_translation'));
        }

        ksort($clean);

        return $clean;
    }

    private function assertSector(string $code): void
    {
        if (! PlatformSector::query()->where('code', $code)->exists()) {
            throw new InvalidArgumentException(__('message_templates.errors.unknown_sector'));
        }
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, MessageTemplateType::ALL, true)) {
            throw new InvalidArgumentException(__('message_templates.errors.unknown_type'));
        }
    }

    private function category(string $type, ?string $requested): string
    {
        return in_array($requested, ['utility', 'marketing'], true) ? (string) $requested : MessageTemplateType::category($type);
    }

    private function newSlug(string $sectorCode, string $seed): string
    {
        $base = Str::of(Str::slug($seed, '_'))->limit(60, '')->toString() ?: 'message';
        $slug = $base;
        $n = 2;

        while ($this->db()->table('message_templates')->where('sector_code', $sectorCode)->where('slug', $slug)->exists()) {
            $slug = $base.'_'.$n++;
        }

        return $slug;
    }

    /** Nom Meta : minuscules, chiffres et tirets bas ; préfixé par le secteur, ce qui isole les secteurs jusque chez Meta. */
    private function metaName(string $sectorCode, string $slug): string
    {
        $name = 'nova_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($sectorCode.'_'.$slug));

        return Str::limit((string) $name, 120, '');
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection(PlatformAudit::CONNECTION);
    }
}
