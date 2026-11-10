<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

class EventTriggerRuleEvaluator
{
    public function __construct(
        private readonly RuleMatcher $matcher,
        private readonly RuleActionRunner $runner,
    ) {}

    /**
     * @param  array<int, string>  $changedFieldKeys
     *
     * @throws Throwable
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
            $firstFailure = null;

            foreach ($rules as $rule) {
                if (!$this->matcher->matchesEvent($rule, $changedFieldKeys, $version)) {
                    continue;
                }

                if (!$this->inScope($rule, $record)) {
                    continue;
                }

                try {
                    $this->runner->run($rule, $record, $stage);
                } catch (Throwable $exception) {
                    $firstFailure ??= $exception;

                    Log::error('Event-based notification rule failed; the remaining rules of the change still run.', [
                        'rule_id' => $rule->getKey(),
                        'record_id' => $record->getKey(),
                        'tenant_id' => $record->tenant_id,
                        'stage' => $stage,
                        'exception' => $exception,
                    ]);
                }
            }

            if ($firstFailure instanceof Throwable) {
                throw $firstFailure;
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
