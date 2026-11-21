<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class MiddlewarePipeline
{
    /**
     * @param  list<string>  $middleware
     */
    private function __construct(private readonly array $middleware) {}

    /**
     * @param  list<class-string>  $only
     */
    public static function forRoute(string $routeName, array $only): self
    {
        $middleware = array_values(array_filter(
            RouteShape::named($routeName)->resolvedMiddleware(),
            static fn (string $entry): bool => in_array($entry, $only, true),
        ));

        $missing = array_values(array_diff($only, $middleware));

        if ($missing !== []) {
            throw new LogicException("Route {$routeName} does not run ".implode(', ', $missing).'.');
        }

        return new self($middleware);
    }

    /**
     * @param  list<class-string>  $middleware
     */
    public static function through(array $middleware): self
    {
        return new self($middleware);
    }

    /**
     * @return list<string>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    /**
     * @param  Closure(Request): Response  $terminal
     */
    public function send(Request $request, Closure $terminal): Response
    {
        return (new Pipeline(app()))
            ->send($request)
            ->through($this->middleware)
            ->then($terminal);
    }
}
