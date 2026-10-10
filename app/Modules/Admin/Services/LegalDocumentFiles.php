<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Exceptions\LegalFileRejected;
use App\Modules\Admin\Models\PlatformLegalVersion;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Support\LegalDocument;
use App\Support\Files\PdfInspector;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Documents juridiques déposés en PDF par le Super Admin (docs/35).
 *
 *  - Déposer un PDF crée une NOUVELLE version publiée, en vigueur immédiatement ; la précédente reste en base, « archivée » (jamais supprimée) :
 *    une version publiée est figée par la base (docs/28), donc archiver = être dépassée par une plus récente.
 *  - Le fichier est contrôlé avant d'être gardé : PDF uniquement (extension, type MIME et signature « %PDF- »), 20 Mo au plus, non vide, sans
 *    contenu actif (JavaScript, lancement de programme, fichier incorporé). Il vit sur le disque PRIVÉ sous un nom aléatoire ; son empreinte SHA-256
 *    est enregistrée.
 *  - La version en vigueur est servie publiquement (/legal/{document}/file) ; les versions archivées ne sont téléchargeables que par le Super Admin.
 *  - Dépôt, remplacement, refus et téléchargement sont tracés dans le journal d'audit de la plateforme. Rien ne se supprime.
 */
final class LegalDocumentFiles
{
    public const MAX_MB = 20;

    public const MAX_BYTES = self::MAX_MB * 1024 * 1024;

    public const DISK = 'local';

    /**
     * Contrôle un fichier avant de le garder.
     *
     * @throws LegalFileRejected
     */
    public function validate(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw LegalFileRejected::because('upload_failed');
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw LegalFileRejected::because('empty');
        }

        if ($size > self::MAX_BYTES) {
            throw LegalFileRejected::because('too_large');
        }

        $path = $file->getRealPath();

        if ($path === false || strtolower($file->getClientOriginalExtension()) !== 'pdf' || $file->getMimeType() !== 'application/pdf') {
            throw LegalFileRejected::because('not_pdf');
        }

        $content = (string) file_get_contents($path);

        if (! PdfInspector::hasSignature($content)) {
            throw LegalFileRejected::because('not_pdf');
        }

        if (PdfInspector::hasActiveContent($content)) {
            throw LegalFileRejected::because('active_content');
        }
    }

    /**
     * Dépose un PDF : il devient la version en vigueur du document, l'ancienne est archivée.
     *
     * @throws LegalFileRejected
     */
    public function replace(string $adminId, string $document, UploadedFile $file, bool $requiresAcceptance = false, ?string $changes = null): PlatformLegalVersion
    {
        abort_unless(in_array($document, LegalDocument::codes(), true), 404);

        try {
            $this->validate($file);
            $latest = $this->latestPublished($document);

            if ($latest !== null && $latest->effective_on->isFuture()) {
                throw LegalFileRejected::because('future_version');
            }
        } catch (LegalFileRejected $e) {
            PlatformAudit::record($adminId, 'legal.file_rejected', null, 'legal_document', null, ['document' => $document, 'reason' => $e->reason(), 'file' => $this->cleanName($file->getClientOriginalName()), 'size' => $file->getSize() ?: null]);

            throw $e;
        }

        $path = null;

        try {
            $path = (string) $file->storeAs("legal/{$document}", (string) Str::uuid7().'.pdf', ['disk' => self::DISK]);
            $sha = (string) hash_file('sha256', (string) Storage::disk(self::DISK)->path($path));
        } catch (Throwable) {
            throw LegalFileRejected::because('store_failed');
        }

        try {
            return DB::connection(PlatformAudit::CONNECTION)->transaction(function () use ($adminId, $document, $file, $requiresAcceptance, $changes, $path, $sha, $latest): PlatformLegalVersion {
                $now = now();
                $summary = $changes === null || trim($changes) === '' ? [] : array_fill_keys(LegalVersionManager::LOCALES, mb_substr(trim($changes), 0, 1000));

                $row = new PlatformLegalVersion;
                $row->forceFill([
                    'document' => $document,
                    'version' => $this->nextVersion($document),
                    'effective_on' => CarbonImmutable::today()->toDateString(),
                    'status' => LegalDocumentVersion::PUBLISHED,
                    'requires_acceptance' => $requiresAcceptance,
                    'title' => collect(LegalVersionManager::LOCALES)->mapWithKeys(fn (string $locale): array => [$locale => (string) __("ui.legal.documents.{$document}", [], $locale)])->all(),
                    'body' => [],
                    'changes' => $summary,
                    'published_at' => $now,
                    'published_by' => $adminId,
                    'created_by' => $adminId,
                    'file_path' => $path,
                    'file_name' => $this->cleanName($file->getClientOriginalName()),
                    'file_size' => (int) $file->getSize(),
                    'file_sha256' => $sha,
                    'uploaded_by' => $adminId,
                    'uploaded_at' => $now,
                ])->save();

                PlatformAudit::record($adminId, $latest === null || ! $latest->hasFile() ? 'legal.file_uploaded' : 'legal.file_replaced', null, 'legal_document_version', $row->id, [
                    'document' => $document, 'version' => $row->version, 'previous_version' => $latest?->version, 'previous_file' => $latest?->file_name,
                    'file' => $row->file_name, 'size' => $row->file_size, 'sha256' => $sha, 'requires_acceptance' => $requiresAcceptance,
                ]);

                return $row;
            });
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($path);

            throw $e instanceof LegalFileRejected ? $e : LegalFileRejected::because('store_failed');
        }
    }

    /**
     * Les trois documents avec leur version en vigueur.
     *
     * @return list<array{code: string, slug: string, name: string, current: array<string, mixed>|null, versions: int}>
     */
    public function overview(): array
    {
        $emails = $this->uploaders();

        return collect(LegalDocument::codes())->map(function (string $code) use ($emails): array {
            $versions = $this->published($code);
            $current = $this->currentOf($versions);

            return [
                'code' => $code,
                'slug' => LegalDocument::slug($code),
                'name' => (string) __("ui.legal.documents.{$code}"),
                'current' => $current === null ? null : $this->describe($current, $current->id, $emails),
                'versions' => $versions->count(),
            ];
        })->all();
    }

    /**
     * Historique d'un document (versions publiées, la plus récente d'abord) : version en vigueur, planifiées, archivées.
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $code): array
    {
        $emails = $this->uploaders();
        $versions = $this->published($code);
        $currentId = $this->currentOf($versions)?->id;

        return $versions->map(fn (PlatformLegalVersion $row): array => $this->describe($row, $currentId, $emails))->values()->all();
    }

    /**
     * Télécharge un PDF depuis le Super Admin (version en vigueur ou archivée) ; chaque téléchargement est tracé.
     */
    public function download(string $adminId, string $document, string $versionId): StreamedResponse
    {
        $row = PlatformLegalVersion::query()->whereKey($versionId)->where('document', $document)->first() ?? abort(404);
        abort_unless($row->hasFile() && Storage::disk(self::DISK)->exists((string) $row->file_path), 404);

        $current = $this->currentOf($this->published($document));
        PlatformAudit::record($adminId, 'legal.file_downloaded', null, 'legal_document_version', $row->id, ['document' => $document, 'version' => $row->version, 'archived' => $current?->id !== $row->id, 'file' => $row->file_name]);

        return Storage::disk(self::DISK)->download((string) $row->file_path, $this->downloadName($row), $this->headers());
    }

    /** Nom de fichier proposé au téléchargement : adresse du document et version, jamais le nom d'origine (qui peut contenir n'importe quoi). */
    public function downloadName(LegalDocumentVersion $row): string
    {
        return LegalDocument::slug($row->document).'-'.$row->version.'.pdf';
    }

    /**
     * En-têtes d'un PDF servi : pas de détection de type, jamais mis en cache durablement (un remplacement doit se voir tout de suite).
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-cache, must-revalidate'];
    }

    /**
     * @param  array<string, string>  $emails
     * @return array<string, mixed>
     */
    private function describe(PlatformLegalVersion $row, ?string $currentId, array $emails): array
    {
        $state = match (true) {
            $row->id === $currentId => 'current',
            $row->effective_on->isFuture() => 'scheduled',
            default => 'archived',
        };

        return [
            'id' => (string) $row->id,
            'document' => $row->document,
            'version' => $row->version,
            'state' => $state,
            'has_file' => $row->hasFile(),
            'file_name' => $row->file_name,
            'file_size' => $row->file_size,
            'effective_on' => $row->effective_on->toDateString(),
            'published_at' => $row->published_at?->toIso8601String(),
            'updated_at' => ($row->uploaded_at ?? $row->published_at)?->toIso8601String(),
            'uploaded_by' => $row->uploaded_by === null ? null : ($emails[$row->uploaded_by] ?? null),
            'requires_acceptance' => $row->requires_acceptance,
            'public_url' => $state === 'current' ? ($row->hasFile() ? '/legal/'.LegalDocument::slug($row->document).'/file' : LegalDocument::url($row->document)) : ($row->hasFile() ? null : LegalDocument::url($row->document, $row->version)),
        ];
    }

    /**
     * @return Collection<int, PlatformLegalVersion>
     */
    private function published(string $code): Collection
    {
        return PlatformLegalVersion::query()->where('document', $code)->where('status', LegalDocumentVersion::PUBLISHED)->orderByDesc('effective_on')->orderByDesc('published_at')->get();
    }

    /**
     * @param  Collection<int, PlatformLegalVersion>  $versions
     */
    private function currentOf(Collection $versions): ?PlatformLegalVersion
    {
        return $versions->first(fn (PlatformLegalVersion $row): bool => ! $row->effective_on->isFuture());
    }

    private function latestPublished(string $code): ?PlatformLegalVersion
    {
        return $this->published($code)->first();
    }

    /** Version du jour : AAAA-MM-JJ, puis .2, .3… quand plusieurs dépôts ont lieu le même jour. */
    private function nextVersion(string $document): string
    {
        $base = CarbonImmutable::today()->toDateString();
        $taken = PlatformLegalVersion::query()->where('document', $document)->where(fn ($q) => $q->where('version', $base)->orWhere('version', 'like', $base.'.%'))->pluck('version')->all();

        for ($n = 1; ; $n++) {
            $candidate = $n === 1 ? $base : "{$base}.{$n}";

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function uploaders(): array
    {
        return DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->pluck('email', 'id')->map(fn ($email): string => (string) $email)->all();
    }

    private function cleanName(string $name): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F\\\\\/]+/u', '', basename($name)));
        $clean = mb_substr($clean === '' ? 'document.pdf' : $clean, -150);

        return str_ends_with(strtolower($clean), '.pdf') ? $clean : $clean.'.pdf';
    }
}
