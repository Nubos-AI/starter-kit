<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordSelectionResolver;
use Illuminate\Database\Eloquent\Builder;

class RuleMatcher
{
    public function __construct(
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly RecordSelectionResolver $selectionResolver,
    ) {}

    /**
     * @return Builder<CustomRecord>
     */
    public function scopeQuery(NotificationRule $rule, ?User $viewer = null): Builder
    {
        $query = CustomRecord::query()->ofType($rule->object_type_id);

        if ($rule->segment_id !== null) {
            $segment = Segment::query()->find($rule->segment_id);

            if ($segment instanceof Segment) {
                $this->applyDefinition(
                    $query,
                    $segment->object_type_id ?? $rule->object_type_id,
                    $segment->filter_definition ?? [],
                    $viewer,
                );
            }

            return $query;
        }

        if (is_array($rule->filter_definition) && $rule->filter_definition !== []) {
            $this->applyDefinition($query, $rule->object_type_id, $rule->filter_definition, $viewer);
        }

        return $query;
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function matchesEvent(NotificationRule $rule, array $changedFieldKeys, int $version): bool
    {
        if (!$rule->trigger_type->isEventBased()) {
            return false;
        }

        return match ($rule->trigger_type) {
            RuleTriggerType::Creation => $version === 1,
            default => in_array(config('modules.notifications.trigger_fields.'.$rule->trigger_type->value), $changedFieldKeys, true),
            RuleTriggerType::Assignment => in_array('owner_id', $changedFieldKeys, true),
            RuleTriggerType::FieldChange => $this->watchedFieldChanged($rule, $changedFieldKeys),
            RuleTriggerType::DateBased => false,
        };
    }

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    private function watchedFieldChanged(NotificationRule $rule, array $changedFieldKeys): bool
    {
        $watched = $rule->config['watched_field_keys'] ?? [];

        if (!is_array($watched)) {
            return false;
        }

        return array_intersect($watched, $changedFieldKeys) !== [];
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  array<string, mixed>  $tree
     */
    private function applyDefinition(Builder $query, string $objectTypeId, array $tree, ?User $viewer): void
    {
        if ($tree === []) {
            return;
        }

        $objectType = ObjectType::query()->whereKey($objectTypeId)->first();

        if (!$objectType instanceof ObjectType) {
            return;
        }

        if ($viewer instanceof User) {
            $scope = $this->selectionResolver->filterScope($objectType, $viewer);

            $this->filterCompiler->applyTree(
                $query,
                $scope['fields'],
                $tree,
                FieldVisibilityResolver::forRequest(),
                $objectType,
                $scope['expressions'],
            );

            return;
        }

        $scope = $this->selectionResolver->systemFilterScope($objectType);

        $this->filterCompiler->applyValidatedTree($query, $scope['fields'], $tree, $scope['expressions']);
    }
}
