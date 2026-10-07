<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use App\Modules\Calendars\Contracts\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Requêtes HTTP vers une adresse saisie par un utilisateur, protégées contre le SSRF (accès au réseau interne du serveur) :
 * HTTPS seulement, port 443 seulement, aucune adresse privée ou réservée (vérifiée sur TOUTES les adresses du nom d'hôte),
 * connexion épinglée sur l'adresse vérifiée (pas de « DNS rebinding »), redirections suivies à la main (3 au plus, chacune
 * revalidée), délai et taille de réponse bornés.
 */
final class SafeHttp
{
    public const MAX_BYTES = 5_000_000;

    private const MAX_REDIRECTS = 3;

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * @param  array<string, string>  $headers
     *
     * @throws CalendarFetchFailed
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, ?string $username = null, ?string $password = null): Response
    {
        $url = $this->normalize($url);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->validate($url);
            $request = Http::withHeaders($headers)->timeout(15)->connectTimeout(8)->withoutRedirecting()->withOptions([
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$target['ip']}"]],
                'on_headers' => function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > self::MAX_BYTES) {
                        throw new CalendarFetchFailed('too_large');
                    }
                },
            ]);

            if ($username !== null && $username !== '') {
                $request = $request->withBasicAuth($username, (string) $password);
            }

            try {
                $response = $body === null ? $request->send($method, $url) : $request->withBody($body, $headers['Content-Type'] ?? 'application/xml')->send($method, $url);
            } catch (ConnectionException) {
                throw new CalendarFetchFailed('timeout');
            }

            if ($response->redirect()) {
                $location = $response->header('Location');

                if ($location === '') {
                    throw new CalendarFetchFailed('redirect');
                }

                $url = $this->normalize($this->absolute($url, $location));

                continue;
            }

            if (strlen($response->body()) > self::MAX_BYTES) {
                throw new CalendarFetchFailed('too_large');
            }

            return $response;
        }

        throw new CalendarFetchFailed('redirect');
    }

    /** `webcal://` est un `https://` déguisé ; tout autre schéma est refusé plus loin. */
    public function normalize(string $url): string
    {
        $url = trim($url);

        return preg_match('#^webcals?://#i', $url) === 1 ? (string) preg_replace('#^webcals?://#i', 'https://', $url) : $url;
    }

    /**
     * @return array{host: string, ip: string}
     *
     * @throws CalendarFetchFailed
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new CalendarFetchFailed('invalid_url');
        }

        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new CalendarFetchFailed('invalid_url');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->ips($host);

        if ($ips === []) {
            throw new CalendarFetchFailed('blocked_host');
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new CalendarFetchFailed('blocked_host');
            }
        }

        return ['host' => $host, 'ip' => str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0]];
    }

    private function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);

        return 'https://'.($parts['host'] ?? '').($location[0] === '/' ? $location : '/'.$location);
    }
}
