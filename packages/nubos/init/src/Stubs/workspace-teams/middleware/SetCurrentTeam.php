<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->route('team');

        if (!$team instanceof Team) {
            $team = Team::query()->where('slug', $team)->first();
        }

        if (!$team) {
            abort(404);
        }

        $currentWorkspace = $request->attributes->get('current_workspace');

        if (!$currentWorkspace) {
            abort(403, 'Workspace context required before team resolution.');
        }

        if ($team->workspace_id !== $currentWorkspace->id) {
            abort(403, 'Team does not belong to current workspace.');
        }

        if (!$request->user()->belongsToTeam($team)) {
            abort(403);
        }

        $request->attributes->set('current_team', $team);

        if ($request->user()->current_team_id !== $team->id) {
            $request->user()->update(['current_team_id' => $team->id]);
        }

        return $next($request);
    }
}
