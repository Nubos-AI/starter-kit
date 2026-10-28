<?php

declare(strict_types=1);

namespace App\Support\Dashboards;

use App\Models\Dashboard;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class DefaultDashboardResolver
{
    public function resolve(User $user): ?Dashboard
    {
        return $this->select($user, $this->available($user), null);
    }

    /**
     * @return Collection<int, Dashboard>
     */
    public function available(User $user): Collection
    {
        if ($user->tenant_id === null) {
            /** @var Collection<int, Dashboard> $empty */
            $empty = new Collection;

            return $empty;
        }

        /** @var Collection<int, Dashboard> $available */
        $available = Dashboard::query()
            ->where('tenant_id', $user->tenant_id)
            ->with('shares')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Dashboard $dashboard): bool => $user->can('view', $dashboard))
            ->sortByDesc($this->isOwned($user))
            ->values();

        return $available;
    }

    /**
     * @param  Collection<int, Dashboard>  $available
     */
    public function select(User $user, Collection $available, ?string $dashboardId): ?Dashboard
    {
        return $this->find($available, $dashboardId)
            ?? $this->find($available, $user->default_dashboard_id)
            ?? $available->first();
    }

    /**
     * @param  Collection<int, Dashboard>  $available
     * @return array{own: array<int, Dashboard>, shared: array<int, Dashboard>}
     */
    public function group(User $user, Collection $available): array
    {
        return [
            'own' => $available->filter($this->isOwned($user))->values()->all(),
            'shared' => $available->reject($this->isOwned($user))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Dashboard>  $available
     */
    private function find(Collection $available, ?string $dashboardId): ?Dashboard
    {
        if ($dashboardId === null || $dashboardId === '') {
            return null;
        }

        return $available->first(
            fn (Dashboard $dashboard): bool => (string) $dashboard->getKey() === $dashboardId,
        );
    }

    /**
     * @return callable(Dashboard): bool
     */
    private function isOwned(User $user): callable
    {
        return fn (Dashboard $dashboard): bool => $dashboard->owner_id === $user->getKey();
    }
}
