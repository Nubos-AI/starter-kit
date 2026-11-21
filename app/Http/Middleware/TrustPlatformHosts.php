<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\WelcomeTenantResolver;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Response;

class TrustPlatformHosts
{
    public function __construct(
        private readonly Application $app,
        private readonly WelcomeTenantResolver $welcome,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app->environment('local') || $this->app->runningUnitTests()) {
            return $next($request);
        }

        Request::setTrustedHosts([]);
        $host = $this->hostOf($request);

        if ($host === null || !$this->isTrusted($host)) {
            Log::warning('A request to a host the platform does not serve was refused.', ['host' => Str::limit($host ?? '', 253, '')]);

            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        Request::setTrustedHosts(['^'.preg_quote($host).'$']);

        try {
            return $next($request);
        } finally {
            Request::setTrustedHosts([]);
        }
    }

    /**
     * @return list<string>
     */
    private function trustedPatterns(): array
    {
        $configured = config('security.trusted_hosts');

        return array_values(array_filter([
            $this->withSubdomains((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
            $this->withSubdomains((string) config('tenancy.welcome.base_domain')),
            ...array_map(
                static fn (string $host): string => '^'.preg_quote(Str::lower($host)).'$',
                array_values(array_filter(is_array($configured) ? $configured : [], is_string(...))),
            ),
        ]));
    }

    private function withSubdomains(string $host): ?string
    {
        $host = Str::lower($host);

        return $host === '' ? null : '^(.+\.)?'.preg_quote($host).'$';
    }

    private function hostOf(Request $request): ?string
    {
        try {
            $host = Str::lower($request->getHost());
        } catch (SuspiciousOperationException $exception) {
            Log::warning('A request with a malformed host was refused.', ['reason' => $exception->getMessage()]);

            return null;
        }

        return $host === '' ? null : $host;
    }

    private function isTrusted(string $host): bool
    {
        foreach ($this->trustedPatterns() as $pattern) {
            if (preg_match("{{$pattern}}i", $host) === 1) {
                return true;
            }
        }

        return $this->welcome->isCertifiable($host);
    }
}
