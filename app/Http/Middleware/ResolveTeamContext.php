<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Support\Teams\ActiveTeamUrlDefault;
use App\Support\Teams\TeamSegment;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

class ResolveTeamContext
{
    public function __construct(private readonly ActiveTeamUrlDefault $urlDefault) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->urlDefault->apply(null);
        $segment = $request->route(TeamSegment::key());

        if (!is_string($segment) || $segment === '') {
            $this->urlDefault->apply($request->user()?->current_team_id);

            return $next($request);
        }

        $request->route()?->forgetParameter(TeamSegment::key());

        $this->urlDefault->apply($segment);

        $user = $request->user();

        if (!$user instanceof User) {
            return $next($request);
        }

        $contextUser = $request->attributes->get('context_user');

        if ($contextUser instanceof User) {
            $this->membershipOrFail($contextUser, $segment);

            return $next($request);
        }

        $team = $this->membershipOrFail($user, $segment);

        app()->instance('current_team', $team);
        Context::addHidden('team_id', (string) $team->getKey());

        return $next($request);
    }

    private function membershipOrFail(User $user, string $segment): Team
    {
        $team = Team::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->whereNull('deleted_at')
            ->where(fn (Builder $query): Builder => $query->where('id', $segment)->orWhere('slug', $segment))
            ->limit(2)
            ->get();

        abort_unless($team->count() === 1, 404);
        $team = $team->sole();

        $isMember = $user->teams()
            ->withoutGlobalScopes()
            ->whereKey($team->getKey())
            ->exists();

        if (!$isMember) {
            abort(403);
        }

        return $team;
    }
}
