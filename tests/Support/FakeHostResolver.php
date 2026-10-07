<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Calendars\Contracts\HostResolver;

/** Résolution DNS simulée : aucun accès réseau dans les tests. */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $hosts
     */
    public function __construct(public array $hosts = []) {}

    public function ips(string $host): array
    {
        return $this->hosts[$host] ?? [];
    }
}
