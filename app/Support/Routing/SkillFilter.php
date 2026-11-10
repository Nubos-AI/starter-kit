<?php

declare(strict_types=1);

namespace App\Support\Routing;

use App\DTOs\Routing\RoutingContext;
use App\Models\CustomRecord;
use App\Models\Skill;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SkillFilter
{
    public function resolveSkillId(CustomRecord $record, RoutingContext $context): ?string
    {
        $query = Skill::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $context->tenantId);

        if ($context->skillId !== null) {
            $query->whereKey($context->skillId);
        } elseif ($context->skillFieldKey !== null) {
            $name = $record->data[$context->skillFieldKey] ?? null;

            if (!is_string($name) || $name === '') {
                return null;
            }

            $query->where('name', $name);
        } else {
            return null;
        }

        $skillId = $query->value('id');

        return is_string($skillId) ? $skillId : null;
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    public function apply(Collection $candidates, ?string $skillId): Collection
    {
        if ($skillId === null) {
            return $candidates;
        }

        $holderIds = array_flip(
            DB::table('skill_user')
                ->where('skill_id', $skillId)
                ->pluck('user_id')
                ->map(static fn (mixed $userId): string => (string) $userId)
                ->all(),
        );

        return $candidates
            ->filter(static fn (User $user): bool => isset($holderIds[(string) $user->getKey()]))
            ->values();
    }
}
