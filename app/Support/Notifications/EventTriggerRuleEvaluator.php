<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Support\Tenancy\TenantContext;

class EventTriggerRuleEvaluator
{
    public function __construct(
        private readonly RuleMatcher $matcher,
        private readonly RuleActionRunner $runner,
    ) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     */
    public function evaluate(string $tenantId, string $objectTypeId, string $recordId, int $version, array $changedFieldKeys): void
    {
        TenantContext::withTenantId($tenantId, function () use ($objectTypeId, $recordId, $version, $changedFieldKeys): void {
            $record = CustomRecord::query()->find($recordId);

            if (!$record instanceof CustomRecord) {
                return;
            }

            $rules = NotificationRule::query()
                ->where('object_type_id', $objectTypeId)
                ->where('is_active', true)
                ->whereIn('trigger_type', RuleTriggerType::eventTypes())
                ->get();

            if ($rules->isEmpty()) {
                return;
            }

            $stage = "v{$version}";

            foreach ($rules as $rule) {
                if (!$this->matcher->matchesEvent($rule, $changedFieldKeys, $version)) {
                    continue;
                }

                if (!$this->inScope($rule, $record)) {
                    continue;
                }

                $this->runner->run($rule, $record, $stage);
            }
        });
    }

    private function inScope(NotificationRule $rule, CustomRecord $record): bool
    {
        if ($rule->segment_id === null && (!is_array($rule->filter_definition) || $rule->filter_definition === [])) {
            return true;
        }

        return $this->matcher->scopeQuery($rule)->whereKey($record->getKey())->exists();
    }
}
