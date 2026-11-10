<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\User;
use App\Support\Engine\DateOffset;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Notifications\RuleMatcher;
use App\Support\Sql\LiteralIdentifier;
use App\Support\Tenancy\TenantBinder;
use App\Support\Watchers\WatcherResolver;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanDateTriggerRules extends Command
{
    private string $keyPattern = '/^[a-z][a-z0-9_]*$/';

    protected $signature = 'notifications:scan-date-triggers';

    protected $description = 'Fire date-based notification rules per recipient timezone across all tenants';

    public function handle(
        RuleMatcher $matcher,
        RuleActionRunner $runner,
        WatcherResolver $watcherResolver,
        MaintenanceLockRegistry $maintenanceLocks,
        TenantBinder $tenantBinder,
        LiteralIdentifier $literal,
        DateOffset $dateOffset,
    ): int {
        $now = CarbonImmutable::now();
        $fallbackTimezone = (string) config('app.timezone');
        $skippedLocked = 0;
        $failedCount = 0;
        $lockedTenantIds = array_flip($maintenanceLocks->lockedTenantIds());

        $rules = NotificationRule::withoutTenantScope()
            ->where('trigger_type', RuleTriggerType::DateBased)
            ->where('is_active', true)
            ->cursor();

        foreach ($rules as $rule) {
            if (isset($lockedTenantIds[$rule->tenant_id])) {
                $skippedLocked++;

                continue;
            }

            try {
                $failedCount += (int) $tenantBinder->runIfKnown(
                    $rule->tenant_id,
                    fn (): int => $this->processRule($rule, $now, $fallbackTimezone, $matcher, $runner, $watcherResolver, $literal, $dateOffset),
                );
            } catch (Throwable $exception) {
                $failedCount++;

                Log::error('Date-based notification rule failed; the scan continues with the next rule.', [
                    'rule_id' => $rule->getKey(),
                    'tenant_id' => $rule->tenant_id,
                    'exception' => $exception,
                ]);
            }
        }

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function processRule(
        NotificationRule $rule,
        CarbonImmutable $now,
        string $fallbackTimezone,
        RuleMatcher $matcher,
        RuleActionRunner $runner,
        WatcherResolver $watcherResolver,
        LiteralIdentifier $literal,
        DateOffset $dateOffset,
    ): int {
        $config = $rule->config ?? [];
        $dateFieldKey = $config['date_field_key'] ?? null;
        $leadStages = $config['lead_stages'] ?? null;
        $failedCount = 0;

        if (!is_string($dateFieldKey) || preg_match($this->keyPattern, $dateFieldKey) !== 1 || !is_array($leadStages)) {
            return $failedCount;
        }

        foreach ($leadStages as $stage) {
            if (!is_array($stage)) {
                continue;
            }

            $value = $stage['value'] ?? null;
            $unit = $stage['unit'] ?? 'day';

            if (!is_int($value) || $value < 0 || !is_string($unit)) {
                continue;
            }

            $stageToken = "{$value}:{$unit}";
            $lower = $now->subDays(2)->toDateString();
            $upper = $dateOffset->add($now, $value, $unit)->addDay()->toDateString();
            $column = "data->>'".$literal->lowerSnake($dateFieldKey)."'";

            $candidates = $matcher->scopeQuery($rule)
                ->whereRaw("{$column} BETWEEN ?::text AND ?::text", [$lower, $upper])
                ->cursor();

            foreach ($candidates as $record) {
                try {
                    $this->processCandidate($rule, $record, $dateFieldKey, $value, $unit, $stageToken, $now, $fallbackTimezone, $runner, $watcherResolver, $dateOffset);
                } catch (Throwable $exception) {
                    $failedCount++;

                    Log::error('Date-based notification rule failed for a record; the scan continues with the next record.', [
                        'rule_id' => $rule->getKey(),
                        'record_id' => $record->getKey(),
                        'tenant_id' => $rule->tenant_id,
                        'stage' => $stageToken,
                        'exception' => $exception,
                    ]);
                }
            }
        }

        return $failedCount;
    }

    private function processCandidate(
        NotificationRule $rule,
        CustomRecord $record,
        string $dateFieldKey,
        int $value,
        string $unit,
        string $stageToken,
        CarbonImmutable $now,
        string $fallbackTimezone,
        RuleActionRunner $runner,
        WatcherResolver $watcherResolver,
        DateOffset $dateOffset,
    ): void {
        $data = $record->data ?? [];
        $isoDate = $data[$dateFieldKey] ?? null;

        if (!is_string($isoDate) || $isoDate === '') {
            return;
        }

        $dueDate = CarbonImmutable::parse($isoDate);
        $referenceDate = $dateOffset->sub($dueDate, $value, $unit)->toDateString();

        $reachedRecipients = $watcherResolver
            ->recipientsFor($record)
            ->filter(static function (User $recipient) use ($referenceDate, $now, $fallbackTimezone): bool {
                $timezone = $recipient->timezone ?? $fallbackTimezone;

                return $now->setTimezone($timezone)->greaterThanOrEqualTo(CarbonImmutable::parse($referenceDate, $timezone)->startOfDay());
            });

        $runner->dispatchToEach($rule, $record, $reachedRecipients, $stageToken);
    }
}
