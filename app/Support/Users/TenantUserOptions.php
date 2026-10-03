<?php

declare(strict_types=1);

namespace App\Support\Users;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TenantUserOptions
{
    private const int LIMIT = 50;

    public function __construct(private readonly UserOptionPresenter $userOptions) {}

    /**
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    public function forUser(User $actingUser, string $term = ''): array
    {
        return $this->present($this->candidates((string) $actingUser->tenant_id, $term));
    }

    /**
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    public function allForUser(User $actingUser): array
    {
        $tenantId = $actingUser->tenant_id;

        if ($tenantId === null) {
            return [];
        }

        return $this->present($this->candidates($tenantId, limit: null));
    }

    /**
     * @return Collection<int, User>
     */
    public function candidates(string $tenantId, string $term = '', ?int $limit = self::LIMIT): Collection
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_service', false)
            ->when($term !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match
                    ->where('first_name', 'ILIKE', "%{$term}%")
                    ->orWhere('last_name', 'ILIKE', "%{$term}%")
                    ->orWhere('email', 'ILIKE', "%{$term}%"),
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->when($limit !== null, fn (Builder $query): Builder => $query->limit($limit))
            ->get();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array{value: string, label: string, description: string, avatar: array{name: string}}>
     */
    public function present(Collection $users): array
    {
        return $this->userOptions->presentMany($users);
    }
}
