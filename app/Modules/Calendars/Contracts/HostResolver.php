<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Contracts;

/**
 * Résolution DNS d'un nom d'hôte (remplaçable en test).
 */
interface HostResolver
{
    /**
     * @return list<string> adresses IP (v4 et v6) du nom d'hôte ; liste vide si introuvable
     */
    public function ips(string $host): array;
}
