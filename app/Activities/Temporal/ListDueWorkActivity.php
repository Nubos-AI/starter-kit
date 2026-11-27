<?php

declare(strict_types=1);

namespace App\Activities\Temporal;

use App\Actions\Notifications\ListDateRuleCasesAction;
use App\Actions\Notifications\ListDueNotificationDigestsAction;
use App\Actions\Reminders\ListDueRemindersAction;
use App\Contracts\Temporal\ListDueWorkActivityInterface;
use App\Support\Temporal\ProcessStepRunner;
use Carbon\CarbonImmutable;
use Keepsuit\LaravelTemporal\Contracts\TemporalSerializable;

class ListDueWorkActivity implements ListDueWorkActivityInterface
{
    public function __construct(
        private readonly ListDueRemindersAction $listDueReminders,
        private readonly ListDateRuleCasesAction $listDateRuleCases,
        private readonly ListDueNotificationDigestsAction $listDueDigests,
        private readonly ProcessStepRunner $steps,
    ) {}

    public function listDueReminders(): array
    {
        return $this->steps->run('scans.list-due-reminders', [], fn (): array => $this->payloads($this->listDueReminders->execute(CarbonImmutable::now())));
    }

    public function listDateRuleCases(): array
    {
        return $this->steps->run('scans.list-date-rule-cases', [], fn (): array => $this->payloads($this->listDateRuleCases->execute(CarbonImmutable::now())));
    }

    public function listDueNotificationDigests(): array
    {
        return $this->steps->run('scans.list-due-digests', [], fn (): array => $this->payloads($this->listDueDigests->execute(CarbonImmutable::now())));
    }

    /**
     * @param  list<TemporalSerializable>  $cases
     * @return list<array<string, mixed>>
     */
    private function payloads(array $cases): array
    {
        return array_map(static fn (TemporalSerializable $case): array => $case->toTemporalPayload(), $cases);
    }
}
