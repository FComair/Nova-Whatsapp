<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Services;

/**
 * Pages publiques d'avis sur Rendivoo (Google, Trustpilot). Les adresses viennent de la configuration (config/rendivoo.php, donc de
 * l'environnement) et ne sont jamais écrites en dur ni inventées : une adresse absente ou invalide (pas en https, ou n'appartenant pas
 * à la plateforme) n'est pas proposée. Tous les clients voient les mêmes liens, dans le même ordre, quelle que soit leur note.
 */
final class ReviewLinks
{
    /** Ordre d'affichage : le même pour tout le monde. */
    public const PLATFORMS = ['google', 'trustpilot'];

    /** Domaines acceptés par plateforme (le domaine lui-même ou un sous-domaine). */
    private const HOSTS = [
        'google' => ['google.com', 'google.be', 'google.fr', 'google.nl', 'g.page', 'goo.gl'],
        'trustpilot' => ['trustpilot.com'],
    ];

    public function url(string $platform): ?string
    {
        $url = config("rendivoo.reviews.{$platform}");

        if (! in_array($platform, self::PLATFORMS, true) || ! is_string($url) || trim($url) === '') {
            return null;
        }

        $parts = parse_url(trim($url));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            return null;
        }

        foreach (self::HOSTS[$platform] as $allowed) {
            if ($host === $allowed || str_ends_with($host, ".{$allowed}")) {
                return trim($url);
            }
        }

        return null;
    }

    /**
     * Plateformes dont le lien est configuré, dans l'ordre fixe.
     *
     * @return list<string>
     */
    public function available(): array
    {
        return array_values(array_filter(self::PLATFORMS, fn (string $platform): bool => $this->url($platform) !== null));
    }
}
