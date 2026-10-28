<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use RuntimeException;

class RouteShape
{
    public function __construct(private readonly Route $route, private readonly Router $router) {}

    public static function named(string $name): self
    {
        $router = app(Router::class);
        $route = $router->getRoutes()->getByName($name);

        if (!$route instanceof Route) {
            throw new RuntimeException("Route [{$name}] is not registered.");
        }

        return new self($route, $router);
    }

    public static function matching(string $method, string $uri): self
    {
        $router = app(Router::class);

        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === ltrim($uri, '/') && in_array(strtoupper($method), $route->methods(), true)) {
                return new self($route, $router);
            }
        }

        throw new RuntimeException("Route [{$method} {$uri}] is not registered.");
    }

    /**
     * @return list<string>
     */
    public function declaredMiddleware(): array
    {
        /** @var list<string> $middleware */
        $middleware = array_values(array_filter(
            $this->route->gatherMiddleware(),
            static fn (mixed $entry): bool => is_string($entry),
        ));

        return $middleware;
    }

    /**
     * @return list<string>
     */
    public function resolvedMiddleware(): array
    {
        /** @var list<string> $middleware */
        $middleware = array_values(array_filter(
            $this->router->gatherRouteMiddleware($this->route),
            static fn (mixed $entry): bool => is_string($entry),
        ));

        return $middleware;
    }

    public function positionOf(string $middleware): ?int
    {
        $position = array_search($middleware, $this->resolvedMiddleware(), true);

        return $position === false ? null : $position;
    }

    public function runsBefore(string $earlier, string $later): bool
    {
        $first = $this->positionOf($earlier);
        $second = $this->positionOf($later);

        return $first !== null && $second !== null && $first < $second;
    }

    public function hasDeclaredMiddleware(string $middleware): bool
    {
        return in_array($middleware, $this->declaredMiddleware(), true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function request(string $uri, string $method = 'GET', array $payload = []): Request
    {
        $request = Request::create($uri, $method, $payload);

        $this->route->bind($request);
        $request->setRouteResolver(fn (): Route => $this->route);

        return $request;
    }

    /**
     * @return array<string, string>
     */
    public function constraints(): array
    {
        /** @var array<string, string> $wheres */
        $wheres = $this->route->wheres;

        return $wheres;
    }

    public function handledBy(): string
    {
        return (string) $this->route->getActionName();
    }

    public function uri(): string
    {
        return $this->route->uri();
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_values($this->route->methods());
    }
}
