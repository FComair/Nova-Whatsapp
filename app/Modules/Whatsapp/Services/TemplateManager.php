<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Support\TemplateCatalog;
use App\Modules\Whatsapp\Support\TemplateInfo;
use App\Modules\Whatsapp\Support\TransportFailed;

/**
 * Modèles de messages de l'établissement chez Meta (étape 8 de l'onboarding, erreur E6) : lesquels existent, lesquels sont approuvés,
 * soumission de ceux qui manquent et synchronisation des statuts. À exécuter sous le contexte de l'organisation.
 *
 * Un message modèle n'est envoyé que s'il est approuvé ; un modèle refusé est resoumis après correction (nouveau nom ou nouveau texte).
 */
final class TemplateManager
{
    public function __construct(private readonly WhatsappTransport $transport, private readonly CurrentTenant $tenant) {}

    /**
     * Les modèles attendus pour le secteur de l'établissement (au moins 4 messages × 3 langues) avec leur statut : `missing` s'ils n'ont
     * jamais été soumis. Le modèle approuvé du secteur remplace le modèle global (docs/39).
     *
     * @return list<array{key: string, name: string, language: string, status: string, reason: string|null, required: bool}>
     */
    public function overview(): array
    {
        $known = WhatsappTemplate::query()->get()->keyBy(fn (WhatsappTemplate $template): string => $template->name.'|'.$template->language);

        return array_map(function (array $entry) use ($known): array {
            $row = $known->get($entry['name'].'|'.$entry['language']);

            return [
                'key' => $entry['key'], 'name' => $entry['name'], 'language' => $entry['language'], 'required' => $entry['required'],
                'status' => $row instanceof WhatsappTemplate ? $row->status : 'missing', 'reason' => $row instanceof WhatsappTemplate ? $row->rejection_reason : null,
            ];
        }, $this->entries());
    }

    /** Vrai quand les quatre messages automatiques sont approuvés par Meta dans toutes les langues. */
    public function allApproved(): bool
    {
        return collect($this->overview())->filter(fn (array $row): bool => $row['required'])->every(fn (array $row): bool => $row['status'] === 'approved');
    }

    /**
     * Soumet à Meta les modèles jamais soumis. Un échec n'arrête pas les autres.
     *
     * @return array{submitted: int, failed: int}
     */
    public function submitMissing(Organization $organization, WhatsappAccount $account, string $userId): array
    {
        $submitted = 0;
        $failed = 0;

        $entries = collect($this->entries())->keyBy(fn (array $entry): string => $entry['name'].'|'.$entry['language']);

        foreach ($this->overview() as $row) {
            if ($row['status'] !== 'missing') {
                continue;
            }

            $entry = $entries->get($row['name'].'|'.$row['language']);

            try {
                $info = $this->transport->createTemplate($account, $row['name'], $row['language'], $entry['body'], $entry['examples']);
            } catch (TransportFailed) {
                $failed++;

                continue;
            }

            $this->store($organization->id, $info, submitted: true);
            $submitted++;
        }

        Audit::record('whatsapp.templates_submitted', 'user', $userId, $organization->id, 'organizations', $organization->id, ['submitted' => $submitted, 'failed' => $failed]);

        return ['submitted' => $submitted, 'failed' => $failed];
    }

    /**
     * Met à jour les statuts depuis Meta pour les modèles que Rendivoo suit.
     *
     * @return int nombre de modèles dont le statut a changé
     *
     * @throws TransportFailed
     */
    public function sync(Organization $organization, WhatsappAccount $account): int
    {
        $expected = collect($this->entries())->map(fn (array $entry): string => $entry['name'].'|'.$entry['language'])->all();
        $changed = 0;

        foreach ($this->transport->listTemplates($account) as $info) {
            if (! in_array($info->name.'|'.$info->language, $expected, true)) {
                continue;
            }

            $existing = WhatsappTemplate::query()->where('name', $info->name)->where('language', $info->language)->first();
            $changed += $existing === null || $existing->status !== $info->status || $existing->rejection_reason !== $info->rejectionReason ? 1 : 0;

            $this->store($organization->id, $info, submitted: false);
        }

        return $changed;
    }

    /**
     * @return list<array{key: string, name: string, language: string, body: string, examples: list<string>, required: bool}>
     */
    private function entries(): array
    {
        return TemplateCatalog::entries($this->tenant->organization()?->sector);
    }

    private function store(string $orgId, TemplateInfo $info, bool $submitted): void
    {
        $template = WhatsappTemplate::query()->firstOrNew(['org_id' => $orgId, 'name' => $info->name, 'language' => $info->language]);

        $template->forceFill([
            'status' => $info->status,
            'rejection_reason' => $info->rejectionReason === null ? null : mb_substr($info->rejectionReason, 0, 255),
            'meta_id' => $info->metaId ?? $template->meta_id,
            'submitted_at' => $submitted ? now() : $template->submitted_at,
            'synced_at' => now(),
        ])->save();
    }
}
