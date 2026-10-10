<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class PermissionResolver
{
    public function __construct(
        private readonly CurrentTeamResolver $currentTeamResolver,
        private readonly ApiAbilityMap $abilityMap,
    ) {}

    public function allows(PermissionHolderInterface $holder, string $ability, ?Model $scope = null): bool
    {
        return $this->contextFor($holder, $scope)->allows($ability);
    }

    public function verdictFor(PermissionHolderInterface $holder, string $ability, ?Model $scope = null): ?bool
    {
        return $this->contextFor($holder, $scope)->ownVerdict($ability);
    }

    /**
     * @param  list<string>  $abilities
     * @return array<string, bool>
     */
    public function map(PermissionHolderInterface $holder, array $abilities, ?Model $scope = null): array
    {
        $context = $this->contextFor($holder, $scope);

        $resolved = [];

        foreach ($abilities as $ability) {
            $resolved[$ability] = $context->allows($ability);
        }

        return $resolved;
    }

    private function contextFor(PermissionHolderInterface $holder, ?Model $scope): PermissionContext
    {
        return new PermissionContext(
            $this->isEscalated($holder, $scope),
            $holder->assignedOverridesFor($scope),
            $this->grantsOf($holder, $scope),
            function () use ($holder, $scope): ?PermissionContext {
                $team = $this->activeTeamOf($holder);

                if (!$team instanceof Team) {
                    return null;
                }

                return new PermissionContext(
                    false,
                    $team->assignedOverridesFor($scope),
                    $this->grantsOf($team, $scope),
                );
            },
        );
    }

    private function isEscalated(PermissionHolderInterface $holder, ?Model $scope): bool
    {
        return $this->assignedRolesOf($holder, $scope)->contains(
            fn (Role $role): bool => $role->authority !== null,
        );
    }

    private function activeTeamOf(PermissionHolderInterface $holder): ?Team
    {
        if (!$holder instanceof User || $this->isServiceHolder($holder)) {
            return null;
        }

        return $this->currentTeamResolver->resolve($holder);
    }

    /**
     * @return array<string, true>
     */
    private function grantsOf(PermissionHolderInterface $holder, ?Model $scope): array
    {
        if ($this->isServiceHolder($holder)) {
            return $this->abilityMap->grantsFor($this->tokenAbilitiesOf($holder));
        }

        /** @var list<string> $names */
        $names = $this->assignedRolesOf($holder, $scope)
            ->flatMap(fn (Role $role): Collection => $role->permissions)
            ->pluck('name')
            ->all();

        return array_fill_keys($names, true);
    }

    private function isServiceHolder(PermissionHolderInterface $holder): bool
    {
        return $holder instanceof User && $holder->is_service;
    }

    /**
     * @return list<string>
     */
    private function tokenAbilitiesOf(PermissionHolderInterface $holder): array
    {
        $token = $holder instanceof User ? $holder->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken ? array_values($token->abilities ?? []) : [];
    }

    /**
     * @return Collection<int, Role>
     */
    private function assignedRolesOf(PermissionHolderInterface $holder, ?Model $scope): Collection
    {
        /** @var Collection<int, Role> $roles */
        $roles = $holder->assignedRolesFor($scope);

        return $roles;
    }
}
