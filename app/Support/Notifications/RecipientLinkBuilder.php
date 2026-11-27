<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use App\Support\Teams\ActiveTeamUrlDefault;
use App\Support\Teams\TeamSegment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

class RecipientLinkBuilder
{
    public function __construct(private readonly ActiveTeamUrlDefault $urlDefault) {}

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function route(string $name, array $parameters, mixed $recipient): ?string
    {
        $segment = $this->segmentFor($recipient);

        if ($segment === null) {
            Log::warning('Notification link omitted because the recipient has no team to address it in.', [
                'recipient_id' => $recipient instanceof User ? $recipient->getKey() : null,
                'route' => $name,
            ]);

            return null;
        }

        return route($name, [TeamSegment::key() => $segment, ...$parameters]);
    }

    private function segmentFor(mixed $recipient): ?string
    {
        $requestSegment = $this->urlDefault->current();

        if (!$recipient instanceof User) {
            return $requestSegment;
        }

        if ($requestSegment !== null && Context::hasHidden('team_id') && $recipient->getKey() === Auth::id()) {
            return $requestSegment;
        }

        $memberTeams = $recipient->teams()
            ->withoutGlobalScopes()
            ->where('teams.tenant_id', $recipient->tenant_id)
            ->whereNull('teams.deleted_at');

        if (is_string($recipient->current_team_id) && $recipient->current_team_id !== '') {
            $memberTeams->orderByRaw('case when "teams"."id" = ? then 0 else 1 end', [$recipient->current_team_id]);
        }

        $teamId = $memberTeams
            ->orderBy('teams.id')
            ->value('teams.id');

        return is_string($teamId) && $teamId !== '' ? $teamId : null;
    }
}
