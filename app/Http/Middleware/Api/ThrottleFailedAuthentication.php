<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ThrottleFailedAuthentication
{
    public int $decaySeconds = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws ThrottleRequestsException
     */
    public function handle(Request $request, Closure $next, string $realm): Response
    {
        $key = "failed-authentication:{$realm}:".hash('sha256', (string) $request->ip());
        $response = $next($request);

        if ($response->getStatusCode() !== Response::HTTP_UNAUTHORIZED) {
            return $response;
        }

        if ($this->limiter->tooManyAttempts($key, (int) config('api.rate_limit.failedAuthenticationsPerMinute'))) {
            Log::warning('An address was throttled after too many rejected authentications.', ['realm' => $realm]);

            throw new ThrottleRequestsException(
                'Too many rejected authentications.',
                null,
                ['Retry-After' => $this->limiter->availableIn($key)],
            );
        }

        $this->limiter->hit($key, $this->decaySeconds);

        return $response;
    }
}
