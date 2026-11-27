<?php

declare(strict_types=1);

namespace App\Http\Middleware\Authorization;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = $request->user();

        if (!$user instanceof User) {
            throw new AuthorizationException(__('i18n.backend.http.middleware.authorization.ensure_permission.please_sign_in'));
        }

        $resolved = $this->resolve($request, $ability);

        if (!$user->hasPermission($resolved)) {
            throw new AuthorizationException(__('i18n.backend.http.middleware.authorization.ensure_permission.you_do_not_have_the_permission', ['value1' => $resolved]));
        }

        return $next($request);
    }

    private function resolve(Request $request, string $ability): string
    {
        if (preg_match('/^\{(?<parameter>[A-Za-z][A-Za-z0-9_]*)\}\.(?<suffix>.+)$/', $ability, $matches) !== 1) {
            return $ability;
        }

        return $this->objectTypeSlug($request, $matches['parameter']).'.'.$matches['suffix'];
    }

    private function objectTypeSlug(Request $request, string $parameter): string
    {
        $bound = $request->route($parameter);

        if ($bound instanceof ObjectType) {
            return $bound->slug;
        }

        if ($bound instanceof CustomRecord) {
            return $bound->objectType->slug;
        }

        throw new LogicException(
            "Route parameter [{$parameter}] does not resolve to an object type.",
        );
    }
}
