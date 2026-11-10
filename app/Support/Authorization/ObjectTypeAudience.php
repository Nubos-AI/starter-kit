<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\CrudAction;
use App\Models\ObjectType;
use App\Models\Team;
use App\Models\User;
use App\Support\Users\UserOptionPresenter;
use Illuminate\Support\Collection;

class ObjectTypeAudience
{
    public function __construct(
        private readonly PermissionResolver $permissionResolver,
        private readonly UserOptionPresenter $userOptions,
    ) {}

    /**
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    public function userOptions(ObjectType $objectType, string $tenantId): array
    {
        $teamVerdicts = $this->teamVerdicts($objectType, $tenantId);

        return $this->userOptions->presentMany(
            User::query()
                ->where('tenant_id', $tenantId)
                ->with('teams')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get()
                ->filter(fn (User $user): bool => $this->userMayView($user, $objectType, $teamVerdicts))
                ->values(),
        );
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function teamOptions(ObjectType $objectType, string $tenantId): array
    {
        return $this->teams($tenantId)
            ->filter(fn (Team $team): bool => $this->grants($team, $objectType))
            ->map(fn (Team $team): array => [
                'value' => (string) $team->getKey(),
                'label' => $team->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, bool>  $teamVerdicts
     */
    private function userMayView(User $user, ObjectType $objectType, array $teamVerdicts): bool
    {
        $own = $this->permissionResolver->verdictFor($user, $this->ability($objectType));

        if ($own !== null) {
            return $own;
        }

        foreach ($user->teams as $team) {
            if ($teamVerdicts[(string) $team->getKey()] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, bool>
     */
    private function teamVerdicts(ObjectType $objectType, string $tenantId): array
    {
        $verdicts = [];

        foreach ($this->teams($tenantId) as $team) {
            $verdicts[(string) $team->getKey()] = $this->grants($team, $objectType);
        }

        return $verdicts;
    }

    /**
     * @return Collection<int, Team>
     */
    private function teams(string $tenantId): Collection
    {
        /** @var Collection<int, Team> $teams */
        $teams = Team::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return $teams;
    }

    private function grants(Team $team, ObjectType $objectType): bool
    {
        return $this->permissionResolver->verdictFor($team, $this->ability($objectType)) === true;
    }

    private function ability(ObjectType $objectType): string
    {
        return $objectType->slug.'.'.CrudAction::View->value;
    }
}
