<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use App\Modules\Calendars\Contracts\HostResolver;

final class DnsHostResolver implements HostResolver
{
    public function ips(string $host): array
    {
        $ips = [];

        foreach ([DNS_A => 'ip', DNS_AAAA => 'ipv6'] as $type => $field) {
            foreach (@dns_get_record($host, $type) ?: [] as $record) {
                if (isset($record[$field])) {
                    $ips[] = (string) $record[$field];
                }
            }
        }

        return $ips;
    }
}
