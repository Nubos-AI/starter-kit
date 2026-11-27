<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Modules\ModuleAccessVerdict;
use App\Models\User;
use App\Support\Modules\DeferredRouteModuleCheck;
use App\Support\Modules\ModuleAccess;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureModuleAvailable
{
    public function __construct(
        private readonly ModuleAccess $access,
        private readonly DeferredRouteModuleCheck $deferred,
        private readonly Router $router,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if (!$route instanceof Route) {
            return $next($request);
        }

        $user = $request->user();
        $isPublic = !$this->requiresAuthentication($route);
        $verdict = $isPublic
            ? $this->access->publicRouteVerdict($route->getName(), $route->getControllerClass())
            : $this->access->routeVerdict($route->getName(), $route->getControllerClass(), $user instanceof User ? $user : null);

        if ($verdict === ModuleAccessVerdict::Allowed) {
            if ($isPublic) {
                $this->deferred->defer($route->getName(), $route->getControllerClass());
            }

            return $next($request);
        }

        Log::info('A module route was refused because its module is not available to the tenant or the user.', [
            'route' => $route->getName(),
            'verdict' => $verdict->value,
            'tenant_id' => TenantContext::currentId(),
            'user_id' => $user?->getAuthIdentifier(),
        ]);

        throw $verdict === ModuleAccessVerdict::Forbidden ? new AccessDeniedHttpException : new NotFoundHttpException;
    }

    private function requiresAuthentication(Route $route): bool
    {
        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (is_string($middleware) && is_a(Str::before($middleware, ':'), AuthenticatesRequests::class, true)) {
                return true;
            }
        }

        return false;
    }
}
