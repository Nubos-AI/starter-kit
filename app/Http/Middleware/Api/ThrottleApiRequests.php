<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleApiRequests extends ThrottleRequests
{
    /**
     * @param  int  $maxAttempts
     * @param  int  $remainingAttempts
     * @param  int|null  $retryAfter
     * @return array<string, int>
     */
    protected function getHeaders($maxAttempts, $remainingAttempts, $retryAfter = null, ?Response $response = null): array
    {
        $headers = parent::getHeaders($maxAttempts, $remainingAttempts, $retryAfter, $response);

        if ($headers === [] || array_key_exists('X-RateLimit-Reset', $headers)) {
            return $headers;
        }

        $headers['X-RateLimit-Reset'] = $this->availableAt(60);

        return $headers;
    }
}
