<?php

declare(strict_types=1);

namespace App\Modules\Legal\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Support\LegalDocument;
use App\Modules\Tenancy\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Documents juridiques versionnés et historique d'acceptation (docs/28).
 *
 * - La version EN VIGUEUR d'un document est la dernière version publiée dont la date d'effet est atteinte.
 * - Une acceptation (`user_consents`) enregistre la version, l'horodatage, le contexte (inscription, nouvelle acceptation, réglages), l'adresse IP,
 *   l'agent utilisateur et la langue affichée ; elle ne change plus jamais, et la même version ne s'enregistre pas deux fois.
 * - Une nouvelle version n'oblige à rien tant qu'elle n'est pas marquée « acceptation requise » : sinon l'utilisateur qui avait accepté une
 *   version antérieure doit l'accepter pour continuer (les utilisateurs sans aucune acceptation enregistrée, par exemple invités avant cette
 *   fonction, reçoivent un avis non bloquant). Le DPA est un accord de l'établissement : il ne concerne que le propriétaire.
 */
final class LegalDocuments
{
    public function __construct(private readonly CurrentTenant $tenant) {}

    /** Version en vigueur d'un document ; null si aucune n'est publiée. */
    public function current(string $code): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('document', $code)->where('status', LegalDocumentVersion::PUBLISHED)
            ->whereDate('effective_on', '<=', CarbonImmutable::today()->toDateString())
            ->orderByDesc('effective_on')->orderByDesc('published_at')->first();
    }

    public function find(string $code, string $version): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('document', $code)->where('version', $version)->where('status', LegalDocumentVersion::PUBLISHED)
            ->whereDate('effective_on', '<=', CarbonImmutable::today()->toDateString())->first();
    }

    /**
     * Historique public d'un document : versions publiées et déjà en vigueur, de la plus récente à la plus ancienne.
     *
     * @return Collection<int, LegalDocumentVersion>
     */
    public function versions(string $code): Collection
    {
        return LegalDocumentVersion::query()->where('document', $code)->where('status', LegalDocumentVersion::PUBLISHED)
            ->whereDate('effective_on', '<=', CarbonImmutable::today()->toDateString())->orderByDesc('effective_on')->orderByDesc('published_at')->get();
    }

    /**
     * Titre et texte dans la langue demandée, à défaut en français puis en anglais (`fallback` vrai : la langue demandée n'existe pas).
     *
     * @return array{title: string, markdown: string, locale: string, fallback: bool}
     */
    public function localised(LegalDocumentVersion $version, string $locale): array
    {
        foreach ([$locale, 'fr', 'en'] as $candidate) {
            $body = trim((string) ($version->body[$candidate] ?? ''));

            if ($body !== '') {
                return [
                    'title' => (string) ($version->title[$candidate] ?? ''),
                    'markdown' => str_replace('{{company}}', $this->company(), $body),
                    'locale' => $candidate,
                    'fallback' => $candidate !== $locale,
                ];
            }
        }

        return ['title' => '', 'markdown' => '', 'locale' => $locale, 'fallback' => true];
    }

    /**
     * Texte rendu en HTML (HTML brut retiré) avec ancres sur les titres de section et table des matières.
     *
     * @return array{html: string, toc: list<array{id: string, title: string}>}
     */
    public function render(string $markdown): array
    {
        $toc = [];
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $match) use (&$toc): string {
            $id = 'section-'.(count($toc) + 1);
            $toc[] = ['id' => $id, 'title' => trim(strip_tags($match[1]))];

            return "<h2 id=\"{$id}\">{$match[1]}</h2>";
        }, $html);

        return ['html' => $html, 'toc' => $toc];
    }

    /**
     * Situation d'un utilisateur pour chaque document : version en vigueur, dernière acceptation, état et historique.
     * États : `accepted`, `missing` (jamais accepté), `reacceptance` (version significative à accepter), `update` (nouvelle version sans acceptation requise),
     * `not_applicable` (DPA pour un autre rôle que propriétaire), `unavailable` (aucune version publiée).
     *
     * @return list<array<string, mixed>>
     */
    public function statuses(User $user, bool $owner, string $locale): array
    {
        $rows = [];

        foreach (LegalDocument::codes() as $code) {
            $current = $this->current($code);
            $accepted = $this->latestAcceptance($user->id, $code);
            $state = $this->state($code, $current, $accepted, $owner);
            $text = $current === null ? null : $this->localised($current, $locale);

            $rows[] = [
                'code' => $code,
                'slug' => LegalDocument::slug($code),
                'title' => $text['title'] ?? '',
                'version' => $current?->version,
                'effective_on' => $current?->effective_on->toDateString(),
                'state' => $state,
                'blocking' => $state === 'reacceptance',
                'accepted' => $accepted === null ? null : ['version' => $accepted->document_version, 'at' => CarbonImmutable::parse($accepted->granted_at)->toIso8601String(), 'context' => $accepted->source],
                'history' => $this->history($user->id, $code),
                'changes' => $current === null ? null : ($current->changes[$locale] ?? $current->changes['fr'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * Documents à accepter, pour l'avis après connexion : jamais accepté (avis simple) ou nouvelle version significative (bloquante).
     *
     * @return list<array{code: string, slug: string, title: string, version: string, blocking: bool}>
     */
    public function pending(User $user, bool $owner, string $locale): array
    {
        $pending = [];

        foreach ($this->statuses($user, $owner, $locale) as $row) {
            if (in_array($row['state'], ['missing', 'reacceptance'], true)) {
                $pending[] = ['code' => $row['code'], 'slug' => $row['slug'], 'title' => $row['title'], 'version' => (string) $row['version'], 'blocking' => $row['blocking']];
            }
        }

        return $pending;
    }

    public function blocked(User $user, bool $owner, string $locale): bool
    {
        foreach ($this->pending($user, $owner, $locale) as $row) {
            if ($row['blocking']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enregistre l'acceptation de la version en vigueur de chaque document demandé (inscription, nouvelle acceptation, réglages).
     * Sans effet pour une version déjà acceptée. Le DPA n'est accepté que par le propriétaire.
     *
     * @param  list<string>  $codes
     * @return list<string> documents effectivement enregistrés
     */
    public function accept(User $user, array $codes, string $context, bool $owner, ?string $ip, ?string $userAgent, string $locale, ?string $orgId = null): array
    {
        $recorded = [];

        foreach ($codes as $code) {
            $current = $this->current($code);

            if ($current === null || ($code === LegalDocument::DPA && ! $owner)) {
                continue;
            }

            $inserted = DB::table('user_consents')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'user_id' => $user->id,
                'type' => $code,
                'document_version' => $current->version,
                'granted_at' => now(),
                'source' => $context,
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'locale' => substr($locale, 0, 2),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($inserted > 0) {
                $recorded[] = $code;
                Audit::record('legal.accepted', 'user', $user->id, $orgId ?? $this->tenant->organization()?->id, 'legal_document', $current->id, ['document' => $code, 'version' => $current->version, 'context' => $context]);
            }
        }

        return $recorded;
    }

    /**
     * Versions en vigueur, pour vérifier à l'envoi ce que l'utilisateur a vu (une version publiée entre l'affichage et l'envoi est refusée).
     *
     * @return array<string, string|null>
     */
    public function currentVersions(): array
    {
        return collect(LegalDocument::codes())->mapWithKeys(fn (string $code): array => [$code => $this->current($code)?->version])->all();
    }

    private function latestAcceptance(string $userId, string $code): ?object
    {
        return DB::table('user_consents')->where('user_id', $userId)->where('type', $code)->orderByDesc('granted_at')->orderByDesc('id')->first();
    }

    /**
     * @return list<array{version: string, at: string, context: string}>
     */
    private function history(string $userId, string $code): array
    {
        return DB::table('user_consents')->where('user_id', $userId)->where('type', $code)->orderByDesc('granted_at')->orderByDesc('id')->get(['document_version', 'granted_at', 'source'])
            ->map(fn (object $row): array => ['version' => (string) $row->document_version, 'at' => CarbonImmutable::parse($row->granted_at)->toIso8601String(), 'context' => (string) $row->source])->all();
    }

    private function state(string $code, ?LegalDocumentVersion $current, ?object $accepted, bool $owner): string
    {
        if ($code === LegalDocument::DPA && ! $owner) {
            return 'not_applicable';
        }

        if ($current === null) {
            return 'unavailable';
        }

        if ($accepted === null) {
            return 'missing';
        }

        if ($accepted->document_version === $current->version) {
            return 'accepted';
        }

        // Une version significative publiée APRÈS celle que l'utilisateur a acceptée exige une nouvelle acceptation, même si une version
        // plus récente et moins significative est parue depuis.
        $acceptedOn = LegalDocumentVersion::query()->where('document', $code)->where('version', $accepted->document_version)->value('effective_on');
        $significant = LegalDocumentVersion::query()->where('document', $code)->where('status', LegalDocumentVersion::PUBLISHED)->where('requires_acceptance', true)
            ->whereDate('effective_on', '<=', CarbonImmutable::today()->toDateString())
            ->when($acceptedOn !== null, fn ($query) => $query->whereDate('effective_on', '>', CarbonImmutable::parse((string) $acceptedOn)->toDateString()))->exists();

        return $significant ? 'reacceptance' : 'update';
    }

    private function company(): string
    {
        $name = trim((string) config('nova.billing.seller.name'));

        // Raison sociale non renseignée (BILLING_SELLER_NAME vide) : le nom du service, jamais une phrase qui se termine dans le vide.
        return $name !== '' ? $name : (string) config('app.name', 'Nova WhatsApp');
    }
}
