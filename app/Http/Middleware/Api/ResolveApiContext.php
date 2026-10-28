<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && !$user->status->canAuthenticate()) {
            throw new AuthenticationException;
        }

        app()->forgetInstance('current_tenant');

        return $next($request);
    }
}
