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

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->isEmbeddable($request)) {
            return $response;
        }

        if (!$response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', $this->frameOptions);
        }

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy);
        }

        return $response;
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
