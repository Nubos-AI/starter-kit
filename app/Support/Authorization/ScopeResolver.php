<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ScopeResolver
{
    public function __construct(private readonly CurrentTeamResolver $currentTeamResolver) {}

    /**
     * @return Collection<int, Model>
     */
    public function resolve(?Model $scope, ?User $user = null): Collection
    {
        if ($scope !== null) {
            return $this->withAncestors($scope);
        }

        return $this->activeScopes($user);
    }

    /**
     * @return Collection<int, Model>
     */
    private function activeScopes(?User $user = null): Collection
    {
        /** @var Collection<int, Model> $scopes */
        $scopes = collect();

        $context = [
            $this->currentTeam($user),
            $this->currentTenant($user),
        ];

        foreach ($context as $scope) {
            if ($scope !== null) {
                $scopes = $scopes->merge($this->withAncestors($scope));
            }
        }

        return $scopes
            ->unique(fn (Model $scope): string => self::signatureFor($scope))
            ->values();
    }

    /**
     * @return Collection<int, Model>
     */
    private function withAncestors(Model $scope): Collection
    {
        $chain = collect([$scope]);

        if ($scope instanceof Team) {
            $chain->push($scope->tenant);
        }

        return $chain
            ->filter()
            ->unique(fn (Model $candidate): string => self::signatureFor($candidate))
            ->values();
    }

    private function currentTenant(?User $user): ?Tenant
    {
        if (app()->bound('current_tenant')) {
            $tenant = app('current_tenant');

            return $tenant instanceof Tenant ? $tenant : null;
        }

        return $user?->tenant_id !== null
            ? Tenant::query()->find($user->tenant_id)
            : null;
    }

    private function currentTeam(?User $user): ?Team
    {
        return $this->currentTeamResolver->resolve($user);
    }

    public static function signatureFor(Model $scope): string
    {
        return $scope->getMorphClass().':'.$scope->getKey();
    }
}
