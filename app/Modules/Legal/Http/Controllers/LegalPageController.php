<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Controllers;

use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Legal\Support\LegalDocument;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages publiques des documents juridiques (docs/28) : lisibles sans connexion, à une adresse stable (/legal/terms-of-use, /legal/privacy-policy,
 * /legal/dpa) qui figure dans l'inscription, les e-mails, les factures et le support. Chaque version publiée garde sa propre adresse
 * (/legal/{document}/versions/{version}) : on peut toujours relire le texte accepté.
 */
final class LegalPageController
{
    public function __construct(private readonly LegalDocuments $documents) {}

    public function show(string $slug): Response
    {
        $code = LegalDocument::code($slug) ?? abort(404);

        return $this->page($code, $this->documents->current($code) ?? abort(404), true);
    }

    public function version(string $slug, string $version): Response
    {
        $code = LegalDocument::code($slug) ?? abort(404);
        $found = $this->documents->find($code, $version) ?? abort(404);

        return $this->page($code, $found, $this->documents->current($code)?->id === $found->id);
    }

    /** Texte brut (Markdown) de la version en vigueur, pour le conserver ou le joindre à un dossier. */
    public function download(string $slug): HttpResponse
    {
        $code = LegalDocument::code($slug) ?? abort(404);
        $version = $this->documents->current($code) ?? abort(404);
        $text = $this->documents->localised($version, app()->getLocale());

        return response("# {$text['title']}\n\n{$text['markdown']}\n", 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$slug.'-'.$version->version.'.md"',
        ]);
    }

    private function page(string $code, LegalDocumentVersion $version, bool $isCurrent): Response
    {
        $locale = app()->getLocale();
        $text = $this->documents->localised($version, $locale);
        $rendered = $this->documents->render($text['markdown']);
        $current = $this->documents->current($code);

        return Inertia::render('Legal/Show', [
            'document' => [
                'code' => $code,
                'slug' => LegalDocument::slug($code),
                'title' => $text['title'],
                'version' => $version->version,
                'effective_on' => $version->effective_on->toDateString(),
                'html' => $rendered['html'],
                'toc' => $rendered['toc'],
                'language' => $text['locale'],
                'fallback' => $text['fallback'],
                'is_current' => $isCurrent,
                'current_url' => LegalDocument::url($code),
                'changes' => $version->changes[$locale] ?? $version->changes['fr'] ?? null,
            ],
            'versions' => $this->documents->versions($code)->map(fn (LegalDocumentVersion $row): array => [
                'version' => $row->version,
                'effective_on' => $row->effective_on->toDateString(),
                'href' => LegalDocument::url($code, $row->version),
                'current' => $current?->id === $row->id,
                'viewing' => $row->id === $version->id,
            ])->values()->all(),
            'documents' => collect(LegalDocument::codes())->map(fn (string $other): array => [
                'code' => $other,
                'slug' => LegalDocument::slug($other),
                'title' => (string) ($this->documents->current($other) === null ? '' : $this->documents->localised($this->documents->current($other), $locale)['title']),
                'active' => $other === $code,
            ])->all(),
        ]);
    }
}
