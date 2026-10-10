<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Notifications\RuleTriggerType;
use App\Models\CustomRecord;
use App\Models\NotificationRule;
use App\Models\Tenant;
use App\Support\Engine\DateOffset;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Notifications\RuleActionRunner;
use App\Support\Notifications\RuleMatcher;
use App\Support\Sql\LiteralIdentifier;
use App\Support\Watchers\WatcherResolver;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;

class ScanDateTriggerRules extends Command
{
    private string $keyPattern = '/^[a-z][a-z0-9_]*$/';

    protected $signature = 'notifications:scan-date-triggers';

    protected $description = 'Fire date-based notification rules per recipient timezone across all tenants';

    public function __construct(
        private readonly RuleMatcher $matcher,
        private readonly RuleActionRunner $runner,
        private readonly WatcherResolver $watcherResolver,
        private readonly MaintenanceLockRegistry $maintenanceLocks,
        private readonly LiteralIdentifier $literal = new LiteralIdentifier,
        private readonly DateOffset $dateOffset = new DateOffset,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $fallbackTimezone = (string) config('app.timezone');
        $skippedLocked = 0;
        $lockedTenantIds = array_flip($this->maintenanceLocks->lockedTenantIds());

        $rules = NotificationRule::withoutTenantScope()
            ->where('trigger_type', RuleTriggerType::DateBased)
            ->where('is_active', true)
            ->cursor();

        foreach ($rules as $rule) {
            if (isset($lockedTenantIds[$rule->tenant_id])) {
                $skippedLocked++;

                continue;
            }

            $this->withTenant($rule->tenant_id, function () use ($rule, $now, $fallbackTimezone): void {
                $this->processRule($rule, $now, $fallbackTimezone);
            });
        }

        return self::SUCCESS;
    }

    private function processRule(NotificationRule $rule, CarbonImmutable $now, string $fallbackTimezone): void
    {
        $config = $rule->config ?? [];
        $dateFieldKey = $config['date_field_key'] ?? null;
        $leadStages = $config['lead_stages'] ?? null;

        if (!is_string($dateFieldKey) || preg_match($this->keyPattern, $dateFieldKey) !== 1 || !is_array($leadStages)) {
            return;
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

            foreach ($this->candidates($rule, $dateFieldKey, $value, $unit, $now) as $record) {
                $this->processCandidate($rule, $record, $dateFieldKey, $value, $unit, $stageToken, $now, $fallbackTimezone);
            }
        }
    }

    /**
     * @return iterable<int, CustomRecord>
     */
    private function candidates(NotificationRule $rule, string $dateFieldKey, int $value, string $unit, CarbonImmutable $now): iterable
    {
        $lower = $now->subDays(2)->toDateString();
        $upper = $this->dateOffset->add($now, $value, $unit)->addDay()->toDateString();

        $column = "data->>'".$this->literal->lowerSnake($dateFieldKey)."'";

        return $this->matcher->scopeQuery($rule)
            ->whereRaw("{$column} BETWEEN ?::text AND ?::text", [$lower, $upper])
            ->cursor();
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
    ): void {
        $data = $record->data ?? [];
        $isoDate = $data[$dateFieldKey] ?? null;

        if (!is_string($isoDate) || $isoDate === '') {
            return;
        }

        $dueDate = CarbonImmutable::parse($isoDate);
        $referenceDate = $this->dateOffset->sub($dueDate, $value, $unit)->toDateString();

        foreach ($this->watcherResolver->recipientsFor($record) as $recipient) {
            $timezone = $recipient->timezone ?? $fallbackTimezone;
            $referenceInstant = CarbonImmutable::parse($referenceDate, $timezone)->startOfDay();

            if ($now->setTimezone($timezone)->greaterThanOrEqualTo($referenceInstant)) {
                $this->runner->dispatchTo($rule, $record, $recipient, $stageToken);
            }
        }
    }

    private function withTenant(string $tenantId, Closure $callback): void
    {
        $previous = app()->bound('current_tenant') ? app('current_tenant') : null;

        try {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant !== null) {
                app()->instance('current_tenant', $tenant);
            }

            $callback();
        } finally {
            app()->forgetInstance('current_tenant');

            if ($previous instanceof Tenant) {
                app()->instance('current_tenant', $previous);
            }
        }
    }
}
