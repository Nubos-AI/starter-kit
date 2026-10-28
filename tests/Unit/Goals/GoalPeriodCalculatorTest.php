<?php

declare(strict_types=1);

use App\Enums\Goals\GoalPeriodType;
use App\Support\Goals\GoalPeriodCalculator;
use Carbon\CarbonImmutable;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->periodBounds = static function (GoalPeriodType $periodType, string $moment): array {
        return app(GoalPeriodCalculator::class)->boundsFor($periodType, new CarbonImmutable($moment));
    };

    $this->periodIso = static function (array $bounds): array {
        expect($bounds)->toHaveKey('start')
            ->and($bounds)->toHaveKey('end')
            ->and($bounds['start'])->toBeInstanceOf(CarbonImmutable::class)
            ->and($bounds['end'])->toBeInstanceOf(CarbonImmutable::class)
            ->and($bounds['start']->getTimezone()->getName())->toBe('UTC')
            ->and($bounds['end']->getTimezone()->getName())->toBe('UTC');

        return [$bounds['start']->toIso8601String(), $bounds['end']->toIso8601String()];
    };
});

test('a monthly period is anchored on local midnight of the configured zone and returned in utc', function (): void {
    $bounds = ($this->periodBounds)(GoalPeriodType::Month, '2026-05-17T10:00:00Z');

    expect(($this->periodIso)($bounds))->toBe(['2026-04-30T22:00:00+00:00', '2026-05-31T22:00:00+00:00']);
});

test('a moment before midnight utc already belongs to the next month of the configured zone', function (): void {
    $bounds = ($this->periodBounds)(GoalPeriodType::Month, '2026-02-28T23:30:00Z');

    expect(($this->periodIso)($bounds))->toBe(['2026-02-28T23:00:00+00:00', '2026-03-31T22:00:00+00:00']);
});

test('a moment inside daylight saving time is bucketed with the two hour offset, not a fixed one', function (): void {
    $bounds = ($this->periodBounds)(GoalPeriodType::Month, '2026-03-31T22:30:00Z');

    expect(($this->periodIso)($bounds))->toBe(['2026-03-31T22:00:00+00:00', '2026-04-30T22:00:00+00:00']);
});

test('the interval is half open so the end of one month is the exact start of the next', function (): void {
    $may = ($this->periodBounds)(GoalPeriodType::Month, '2026-05-17T10:00:00Z');
    $june = ($this->periodBounds)(GoalPeriodType::Month, '2026-06-17T10:00:00Z');
    $following = ($this->periodBounds)(GoalPeriodType::Month, $may['end']->toIso8601String());

    expect($may['end']->equalTo($june['start']))->toBeTrue()
        ->and($following['start']->equalTo($may['end']))->toBeTrue()
        ->and($may['end']->second)->toBe(0)
        ->and($may['end']->minute)->toBe(0);
});

test('a quarterly period spans the calendar quarter of the configured zone', function (): void {
    $bounds = ($this->periodBounds)(GoalPeriodType::Quarter, '2026-08-15T12:00:00Z');

    expect(($this->periodIso)($bounds))->toBe(['2026-06-30T22:00:00+00:00', '2026-09-30T22:00:00+00:00']);
});

test('a yearly period starts and ends on the local new year, not on the utc one', function (): void {
    $bounds = ($this->periodBounds)(GoalPeriodType::Year, '2026-08-15T12:00:00Z');

    expect(($this->periodIso)($bounds))->toBe(['2025-12-31T23:00:00+00:00', '2026-12-31T23:00:00+00:00']);
});

test('february of a leap year covers the twenty ninth as its last local day', function (): void {
    $bounds = ($this->periodBounds)(GoalPeriodType::Month, '2028-02-10T12:00:00Z');
    $lastLocalSecond = $bounds['end']->setTimezone((string) config('reports.timezone'))->subSecond();

    expect(($this->periodIso)($bounds))->toBe(['2028-01-31T23:00:00+00:00', '2028-02-29T23:00:00+00:00'])
        ->and($lastLocalSecond->format('Y-m-d'))->toBe('2028-02-29');
});

test('the zone comes from the report configuration and is never a hard coded literal', function (): void {
    config(['reports.timezone' => 'UTC']);

    $bounds = ($this->periodBounds)(GoalPeriodType::Month, '2026-02-28T23:30:00Z');

    expect(($this->periodIso)($bounds))->toBe(['2026-02-01T00:00:00+00:00', '2026-03-01T00:00:00+00:00']);
});
