<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

class SetFramingHeaders
{
    private string $frameOptions = 'SAMEORIGIN';

    private string $contentSecurityPolicy = "frame-ancestors 'self'";

    private string $contentTypeOptions = 'nosniff';

    private string $referrerPolicy = 'strict-origin-when-cross-origin';

    private string $strictTransportSecurity = 'max-age=31536000; includeSubDomains';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $this->setUnlessPresent($response, 'X-Content-Type-Options', $this->contentTypeOptions);
        $this->setUnlessPresent($response, 'Referrer-Policy', $this->referrerPolicy);

        if ($request->isSecure()) {
            $this->setUnlessPresent($response, 'Strict-Transport-Security', $this->strictTransportSecurity);
        }

        if ($this->isEmbeddable($request)) {
            return $response;
        }

        $this->setUnlessPresent($response, 'X-Frame-Options', $this->frameOptions);
        $this->setUnlessPresent($response, 'Content-Security-Policy', $this->contentSecurityPolicy);

        return $response;
    }

    private function setUnlessPresent(Response $response, string $header, string $value): void
    {
        if (!$response->headers->has($header)) {
            $response->headers->set($header, $value);
        }
    }

    private function isEmbeddable(Request $request): bool
    {
        $route = $request->route();
        $patterns = config('security.frame_embeddable_routes');

        if (!$route instanceof Route || !is_array($patterns) || $patterns === []) {
            return false;
        }

        return $route->named(...array_values(array_filter($patterns, is_string(...))));
    }
}
