<?php

declare(strict_types=1);

namespace App\Support\Webhooks;

use App\Exceptions\Webhooks\SsrfBlockedException;
use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

class SsrfGuard
{
    /**
     * @var array<int, string>
     */
    private array $metadataAndLoopback = ['169.254.169.254', '::1'];

    /**
     * @var array<int, string>
     */
    private array $nonGlobalRanges = ['100.64.0.0/10', '64:ff9b::/96', '2002::/16'];

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver
     */
    public function __construct(private readonly ?Closure $resolver = null) {}

    public function assertAllowed(string $url): string
    {
        return $this->assert($url, true);
    }

    public function assertRedirectAllowed(string $url): string
    {
        return $this->assert($url, true);
    }

    public function assertWebhookTargetAllowed(string $url): string
    {
        return $this->assert($url, false);
    }

    public function assertAllowedWithoutResolving(string $url): void
    {
        $this->checkTargetWithoutResolving($url, true);
    }

    public function resolveEntryFor(string $url, string $pinnedIp): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $host = rtrim($host, '.');
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return "{$host}:{$port}:{$pinnedIp}";
    }

    private function assert(string $url, bool $enforceAllowlist): string
    {
        $host = $this->checkTargetWithoutResolving($url, $enforceAllowlist);

        $ips = $this->targetIps($host);

        if ($ips === []) {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.ssrf_guard.the_hostname_could_not_be_resolved_to_an_ip'), $url);
        }

        foreach ($ips as $ip) {
            $this->assertIpNotBlocked($ip, $url);
        }

        return $ips[0];
    }

    private function checkTargetWithoutResolving(string $url, bool $enforceAllowlist): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.ssrf_guard.the_address_is_malformed'), $url);
        }

        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.ssrf_guard.only_http_and_https_are_allowed'), $url);
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.ssrf_guard.credentials_in_the_address_are_not_allowed'), $url);
        }

        $host = strtolower($parts['host']);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $host = rtrim($host, '.');

        if ($host === '') {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.ssrf_guard.the_address_has_no_hostname'), $url);
        }

        if ($enforceAllowlist) {
            $this->assertHostIsAllowlisted($host, $url);
        }

        $literal = $this->ipLiteralOf($host);

        if ($literal !== null) {
            $this->assertIpNotBlocked($literal, $url);
        }

        return $host;
    }

    private function ipLiteralOf(string $host): ?string
    {
        $candidate = $this->normalizeNumericHost($host) ?? $host;

        return filter_var($candidate, FILTER_VALIDATE_IP) !== false ? $candidate : null;
    }

    private function assertIpNotBlocked(string $ip, string $url): void
    {
        if ($this->ipIsBlocked($ip)) {
            throw new SsrfBlockedException(
                __('i18n.backend.support.webhooks.ssrf_guard.the_hostname_resolves_to_a_private_or_reserved_ip'),
                $url,
                $ip,
            );
        }
    }

    private function assertHostIsAllowlisted(string $host, string $url): void
    {
        /** @var array<int, string> $allowedHosts */
        $allowedHosts = (array) config('automation.http.allowed_hosts', []);

        if (!in_array($host, $allowedHosts, true)) {
            throw new SsrfBlockedException(__('i18n.backend.support.webhooks.ssrf_guard.the_hostname_is_not_on_the_list_of_allowed'), $url);
        }
    }

    /**
     * @return array<int, string>
     */
    private function targetIps(string $host): array
    {
        $literal = $this->ipLiteralOf($host);

        return $literal !== null ? [$literal] : $this->resolve($host);
    }

    /**
     * @return array<int, string>
     */
    private function resolve(string $host): array
    {
        $resolver = $this->resolver ?? fn (string $target): array => $this->defaultResolver($target);

        $ips = $resolver($host);

        return array_values(array_filter($ips, static fn (string $ip): bool => $ip !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function defaultResolver(string $host): array
    {
        $records = gethostbynamel($host);
        $ips = $records === false ? [] : $records;

        $aaaa = @dns_get_record($host, DNS_AAAA);

        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function ipIsBlocked(string $ip): bool
    {
        $candidate = $this->extractMappedIpv4($ip) ?? $ip;

        if (in_array(strtolower($candidate), $this->metadataAndLoopback, true)) {
            return true;
        }

        if (IpUtils::checkIp($candidate, $this->nonGlobalRanges)) {
            return true;
        }

        return filter_var(
            $candidate,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    private function extractMappedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        if (substr($packed, 0, 12) !== "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
            return null;
        }

        $v4 = @inet_ntop(substr($packed, 12));

        return $v4 === false ? null : $v4;
    }

    private function normalizeNumericHost(string $host): ?string
    {
        $parts = explode('.', $host);
        $count = count($parts);

        if ($count > 4) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->parseNumericPart($part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        $long = $this->assembleIpv4($values);

        if ($long === null) {
            return null;
        }

        return long2ip($long);
    }

    private function parseNumericPart(string $part): ?int
    {
        if (preg_match('/^0x[0-9a-f]+$/i', $part) === 1) {
            return (int) hexdec($part);
        }

        if (preg_match('/^0[0-7]+$/', $part) === 1) {
            return (int) octdec($part);
        }

        if (preg_match('/^(0|[1-9][0-9]*)$/', $part) === 1) {
            return (int) $part;
        }

        return null;
    }

    /**
     * @param  array<int, int>  $values
     */
    private function assembleIpv4(array $values): ?int
    {
        $count = count($values);
        $maxLast = [1 => 0xFFFFFFFF, 2 => 0xFFFFFF, 3 => 0xFFFF, 4 => 0xFF][$count];

        for ($i = 0; $i < $count - 1; $i++) {
            if ($values[$i] < 0 || $values[$i] > 0xFF) {
                return null;
            }
        }

        $last = $values[$count - 1];

        if ($last < 0 || $last > $maxLast) {
            return null;
        }

        $long = $last;

        for ($i = 0; $i < $count - 1; $i++) {
            $long |= $values[$i] << (8 * (3 - $i));
        }

        return $long & 0xFFFFFFFF;
    }
}
