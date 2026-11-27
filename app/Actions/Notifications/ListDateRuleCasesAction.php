<?php

declare(strict_types=1);

namespace App\Actions\Notifications;

use App\DTOs\Notifications\DateRuleCase;
use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\NotificationRuleDispatch;
use App\Models\User;
use App\Support\Engine\DateOffset;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\RuleMatcher;
use App\Support\Sql\LiteralIdentifier;
use App\Support\Tenancy\TenantBinder;
use App\Support\Watchers\WatcherResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ListDateRuleCasesAction
{
    private string $keyPattern = '/^[a-z][a-z0-9_]*$/D';

    public function __construct(
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly RuleMatcher $matcher,
        private readonly WatcherResolver $watcherResolver,
        private readonly TenantBinder $tenantBinder,
        private readonly LiteralIdentifier $literal,
        private readonly DateOffset $dateOffset,
        private readonly CreateRuleFollowUpReminderAction $followUpReminder,
        private readonly Repository $config,
    ) {}

    /**
     * @return list<DateRuleCase>
     */
    public function execute(CarbonImmutable $now): array
    {
        $lockedTenantIds = $this->maintenanceLocks->lockedTenantIds();
        $limit = max(1, (int) $this->config->get('record-processing.scans.max_cases_per_run'));
        $cases = [];

        $rules = NotificationRule::withoutTenantScope()
            ->where('trigger_type', RuleTriggerType::DateBased)
            ->where('is_active', true)
            ->when($lockedTenantIds !== [], static fn (Builder $query): Builder => $query->whereNotIn('notification_rules.tenant_id', $lockedTenantIds))
            ->cursor();

        foreach ($rules as $rule) {
            try {
                $ruleCases = $this->tenantBinder->runIfKnown($rule->tenant_id, fn (): array => $this->casesOf($rule, $now, $limit - count($cases))) ?? [];
            } catch (Throwable $exception) {
                Log::error('Date-based notification rule could not be scanned; the scan continues with the next rule.', [
                    'rule_id' => $rule->getKey(),
                    'tenant_id' => $rule->tenant_id,
                    'exception' => $exception::class,
                ]);

                continue;
            }

            $cases = [...$cases, ...$ruleCases];

            if (count($cases) >= $limit) {
                break;
            }
        }

        return $cases;
    }

    /**
     * @return list<DateRuleCase>
     */
    private function casesOf(NotificationRule $rule, CarbonImmutable $now, int $remaining): array
    {
        $config = $rule->config ?? [];
        $dateFieldKey = $config['date_field_key'] ?? null;
        $leadStages = $config['lead_stages'] ?? null;
        $cases = [];

        if (!is_string($dateFieldKey) || preg_match($this->keyPattern, $dateFieldKey) !== 1 || !is_array($leadStages)) {
            return $cases;
        }

        foreach ($leadStages as $stage) {
            $value = is_array($stage) ? ($stage['value'] ?? null) : null;
            $unit = is_array($stage) ? ($stage['unit'] ?? 'day') : null;

            if (!is_int($value) || $value < 0 || !is_string($unit)) {
                continue;
            }

            $column = "data->>'".$this->literal->lowerSnake($dateFieldKey)."'";

            $candidates = $this->matcher->scopeQuery($rule)
                ->whereRaw("{$column} BETWEEN ?::text AND ?::text", [
                    $now->subDays(2)->toDateString(),
                    $this->dateOffset->add($now, $value, $unit)->addDay()->toDateString(),
                ])
                ->cursor();

            foreach ($candidates as $record) {
                $case = $this->caseOf($rule, $record, $dateFieldKey, $value, $unit, $now);

                if ($case instanceof DateRuleCase) {
                    $cases[] = $case;
                }

                if (count($cases) >= $remaining) {
                    return $cases;
                }
            }
        }

        return $cases;
    }

    private function caseOf(NotificationRule $rule, CustomRecord $record, string $dateFieldKey, int $value, string $unit, CarbonImmutable $now): ?DateRuleCase
    {
        $isoDate = ($record->data ?? [])[$dateFieldKey] ?? null;

        if (!is_string($isoDate) || $isoDate === '') {
            return null;
        }

        $stage = "{$value}:{$unit}";
        $referenceDate = $this->dateOffset->sub(CarbonImmutable::parse($isoDate), $value, $unit)->toDateString();
        $fallbackTimezone = (string) $this->config->get('app.timezone');

        $reachedIds = $this->watcherResolver
            ->recipientsFor($record)
            ->filter(static function (User $recipient) use ($referenceDate, $now, $fallbackTimezone): bool {
                $timezone = $recipient->timezone ?? $fallbackTimezone;

                return $now->setTimezone($timezone)->greaterThanOrEqualTo(CarbonImmutable::parse($referenceDate, $timezone)->startOfDay());
            })
            ->map(static fn (User $recipient): string => (string) $recipient->getKey())
            ->values()
            ->all();

        if ($reachedIds === []) {
            return null;
        }

        $dispatchedIds = NotificationRuleDispatch::query()
            ->where('rule_id', $rule->getKey())
            ->where('record_id', $record->getKey())
            ->where('stage', $stage)
            ->whereNotNull('dispatched_at')
            ->pluck('user_id')
            ->map(strval(...))
            ->all();

        $pendingIds = array_values(array_diff($reachedIds, $dispatchedIds));
        $hasFollowUpReminder = $this->followUpReminder->isConfigured($rule);

        if ($pendingIds === [] && !($hasFollowUpReminder && $this->isFollowUpReminderOpen($rule, $record, $stage))) {
            return null;
        }

        return new DateRuleCase($rule->tenant_id, (string) $rule->getKey(), (string) $record->getKey(), $stage, $pendingIds, $hasFollowUpReminder);
    }

    private function isFollowUpReminderOpen(NotificationRule $rule, CustomRecord $record, string $stage): bool
    {
        return !NotificationRuleDispatch::query()
            ->where('rule_id', $rule->getKey())
            ->where('record_id', $record->getKey())
            ->where('user_id', $rule->created_by_id)
            ->where('stage', "{$stage}:reminder")
            ->exists();
    }
}
