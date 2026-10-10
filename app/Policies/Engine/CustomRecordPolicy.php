<?php

declare(strict_types=1);

namespace App\Policies\Engine;

use App\Models\CustomRecord;
use App\Models\User;
use App\Scopes\TeamRecordAccessScope;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;

class CustomRecordPolicy
{
    public function __construct(
        private readonly RowAccessEnforcement $enforcement,
        private readonly TeamAccessRuleResolver $ruleResolver,
        private readonly RecordAccessRuleCompiler $ruleCompiler,
    ) {}

    public function view(User $user, CustomRecord $record): bool
    {
        return $user->hasPermission($this->ability($record, 'view'))
            && $this->isWithinRowAccess($user, $record);
    }

    public function update(User $user, CustomRecord $record): bool
    {
        return $user->hasPermission($this->ability($record, 'update'))
            && $this->isWithinRowAccess($user, $record);
    }

    public function delete(User $user, CustomRecord $record): bool
    {
        return $user->hasPermission($this->ability($record, 'delete'));
    }

    public function restore(User $user, CustomRecord $record): bool
    {
        return $user->hasPermission($this->ability($record, 'delete'));
    }

    public function forceDelete(User $user, CustomRecord $record): bool
    {
        return $user->hasPermission($this->ability($record, 'delete'));
    }

    public function merge(User $user, CustomRecord $record): bool
    {
        return $user->hasPermission($this->ability($record, 'merge'))
            && $user->hasPermission($this->ability($record, 'update'))
            && $this->isWithinRowAccess($user, $record);
    }

    private function isWithinRowAccess(User $user, CustomRecord $record): bool
    {
        if (!$this->enforcement->isEnabled()) {
            return true;
        }

        $rules = $this->ruleResolver->resolveFor($user);

        if (!in_array($record->object_type_id, $rules->objectTypeIds(), true)) {
            return true;
        }

        $query = CustomRecord::query()
            ->withoutGlobalScope(TeamRecordAccessScope::class)
            ->withTrashed()
            ->whereKey($record->getKey());

        $this->ruleCompiler->apply($query, $rules, $record->getTable());

        return $query->exists();
    }

    private function ability(CustomRecord $record, string $action): string
    {
        return "{$record->objectType->slug}.{$action}";
    }
}
