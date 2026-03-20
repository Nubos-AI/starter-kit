<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectToCurrentOrg
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        $workspace = $user->currentWorkspace ?? $user->workspaces()->first();
        $team = $user->currentTeam ?? $workspace?->teams()->first();

        if ($workspace && $team) {
            return redirect("/workspaces/{$workspace->slug}/teams/{$team->slug}/dashboard");
        }

        if ($workspace) {
            return redirect("/workspaces/{$workspace->slug}/dashboard");
        }

        abort(404);
    }
}
