<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformLegalVersion;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Support\LegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Publication des documents juridiques par le Super Admin (docs/28). Règles :
 * - un brouillon se modifie et se supprime librement ; une version PUBLIÉE ne change plus jamais (la base l'interdit aussi) : un nouveau
 *   texte est toujours une nouvelle version, avec sa date d'effet ;
 * - on ne publie pas avec une date d'effet passée, ni antérieure ou égale à celle de la version déjà publiée : l'historique reste ordonné et
 *   ce que les utilisateurs ont accepté n'est jamais réécrit après coup ;
 * - « acceptation requise » marque une version juridiquement significative : les utilisateurs qui avaient accepté la précédente doivent l'accepter ;
 * - toute écriture est tracée dans le journal d'audit de la plateforme.
 */
final class LegalVersionManager
{
    public const LOCALES = ['fr', 'nl', 'en'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, string $adminId): PlatformLegalVersion
    {
        AdminAccess::authorize($adminId, 'legal.upload');
        $version = new PlatformLegalVersion($this->attributes($data) + ['status' => LegalDocumentVersion::DRAFT, 'created_by' => $adminId]);
        $version->save();
        PlatformAudit::record($adminId, 'legal.version_created', null, 'legal_document_version', $version->id, ['document' => $version->document, 'version' => $version->version]);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException `published` : une version publiée ne se modifie pas
     */
    public function update(PlatformLegalVersion $version, array $data, string $adminId): PlatformLegalVersion
    {
        AdminAccess::authorize($adminId, 'legal.upload');
        $this->assertDraft($version);
        $version->forceFill($this->attributes($data))->save();
        PlatformAudit::record($adminId, 'legal.version_updated', null, 'legal_document_version', $version->id, ['document' => $version->document, 'version' => $version->version]);

        return $version;
    }

    /**
     * @throws InvalidArgumentException `published`, `no_text`, `past_date` ou `not_after_current`
     */
    public function publish(PlatformLegalVersion $version, string $adminId): PlatformLegalVersion
    {
        AdminAccess::authorize($adminId, 'legal.upload');
        $this->assertDraft($version);

        if (! $this->hasText($version)) {
            throw new InvalidArgumentException('no_text');
        }

        if ($version->effective_on->lt(CarbonImmutable::today())) {
            throw new InvalidArgumentException('past_date');
        }

        $latest = PlatformLegalVersion::query()->where('document', $version->document)->where('status', LegalDocumentVersion::PUBLISHED)->max('effective_on');

        if ($latest !== null && $version->effective_on->lte(CarbonImmutable::parse((string) $latest))) {
            throw new InvalidArgumentException('not_after_current');
        }

        DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($version, $adminId): void {
            $version->forceFill(['status' => LegalDocumentVersion::PUBLISHED, 'published_at' => now(), 'published_by' => $adminId])->save();
            PlatformAudit::record($adminId, 'legal.version_published', null, 'legal_document_version', $version->id, [
                'document' => $version->document,
                'version' => $version->version,
                'effective_on' => $version->effective_on->toDateString(),
                'requires_acceptance' => $version->requires_acceptance,
            ]);
        });

        return $version;
    }

    /**
     * @throws InvalidArgumentException `published`
     */
    public function delete(PlatformLegalVersion $version, string $adminId): void
    {
        AdminAccess::authorize($adminId, 'legal.upload');
        $this->assertDraft($version);
        $version->delete();
        PlatformAudit::record($adminId, 'legal.version_deleted', null, 'legal_document_version', $version->id, ['document' => $version->document, 'version' => $version->version]);
    }

    /** Un texte au moins (français ou anglais, avec son titre) est nécessaire pour publier : les autres langues retombent dessus. */
    public function hasText(LegalDocumentVersion $version): bool
    {
        foreach (['fr', 'en'] as $locale) {
            if (trim((string) ($version->body[$locale] ?? '')) !== '' && trim((string) ($version->title[$locale] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $pick = static fn (string $prefix): array => collect(self::LOCALES)->mapWithKeys(fn (string $locale): array => [$locale => trim((string) ($data["{$prefix}_{$locale}"] ?? ''))])->filter(fn (string $text): bool => $text !== '')->all();

        return [
            'document' => (string) $data['document'],
            'version' => trim((string) $data['version']),
            'effective_on' => CarbonImmutable::parse((string) $data['effective_on'])->toDateString(),
            'requires_acceptance' => (bool) ($data['requires_acceptance'] ?? false),
            'title' => $pick('title'),
            'body' => $pick('body'),
            'changes' => $pick('changes'),
        ];
    }

    private function assertDraft(PlatformLegalVersion $version): void
    {
        if ($version->isPublished()) {
            throw new InvalidArgumentException('published');
        }
    }

    /** @return list<string> */
    public static function documents(): array
    {
        return LegalDocument::codes();
    }
}
