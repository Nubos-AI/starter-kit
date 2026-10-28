<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\Tenancy\DnsLookupFailedException;
use ErrorException;
use Illuminate\Support\Facades\Log;

class DnsLookup
{
    /**
     * @throws DnsLookupFailedException
     */
    public function cnameOf(string $host): ?string
    {
        $target = collect($this->recordsOf($host, DNS_CNAME))
            ->pluck('target')
            ->first(static fn (mixed $target): bool => is_string($target) && $target !== '');

        return is_string($target) ? $target : null;
    }

    /**
     * @return list<string>
     *
     * @throws DnsLookupFailedException
     */
    public function addressesOf(string $host): array
    {
        $addresses = [];

        foreach ($this->recordsOf($host, DNS_A | DNS_AAAA) as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($address) && !in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }

        sort($addresses);

        return $addresses;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws DnsLookupFailedException
     */
    private function recordsOf(string $host, int $type): array
    {
        try {
            $records = dns_get_record($host, $type);
        } catch (ErrorException $exception) {
            Log::warning('DNS lookup failed.', ['host' => $host, 'type' => $type, 'error' => $exception->getMessage()]);

            throw new DnsLookupFailedException($host, $exception);
        }

        if ($records === false) {
            Log::warning('DNS lookup failed.', ['host' => $host, 'type' => $type]);

            throw new DnsLookupFailedException($host);
        }

        return $records;
    }
}
