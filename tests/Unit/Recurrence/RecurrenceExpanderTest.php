<?php

declare(strict_types=1);

use App\DTOs\Recurrence\RecurrenceRule;
use App\Enums\Recurrence\RecurrenceFrequency;
use App\Exceptions\Recurrence\InvalidRecurrenceRuleException;
use App\Support\Recurrence\RecurrenceExpander;
use Carbon\CarbonImmutable;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['reports.timezone' => 'Europe/Berlin', 'recurrence.max_occurrences' => 500]);
    app()->setLocale('de');
    $this->expander = app(RecurrenceExpander::class);
    $this->iso = static fn (array $occurrences): array => array_map(static fn (CarbonImmutable $occurrence): string => $occurrence->toIso8601ZuluString(), $occurrences);
});

it('names every frequency in German', function (): void {
    expect(array_map(static fn (RecurrenceFrequency $frequency): string => $frequency->label(), RecurrenceFrequency::cases()))
        ->toBe(['Täglich', 'Wöchentlich', 'Monatlich', 'Jährlich']);
});

it('refuses a rule whose interval lies outside one to the allowed maximum', function (int $interval): void {
    expect(fn (): RecurrenceRule => new RecurrenceRule(RecurrenceFrequency::Daily, $interval, null))
        ->toThrow(InvalidRecurrenceRuleException::class);
})->with([0, -1, 100]);

it('builds no rule without a frequency and a rule with the default interval of one otherwise', function (): void {
    $rule = RecurrenceRule::fromAttributes(RecurrenceFrequency::Weekly, null, null);

    expect(RecurrenceRule::fromAttributes(null, 2, null))->toBeNull()
        ->and($rule?->interval)->toBe(1)
        ->and($rule?->toPayload())->toBe(['frequency' => 'weekly', 'interval' => 1, 'endsAt' => null]);
});

it('lists the occurrences of a weekly series inside the window and keeps the local wall clock across daylight saving time', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Weekly, 1, null);

    $occurrences = $this->expander->between(
        CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'),
        $rule,
        CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-11-03 00:00:00', 'UTC'),
    );

    expect(($this->iso)($occurrences))->toBe([
        '2026-10-20T08:00:00Z',
        '2026-10-27T09:00:00Z',
    ]);
});

it('includes the first occurrence when it lies inside the window and honours the interval', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Daily, 3, null);

    $occurrences = $this->expander->between(
        CarbonImmutable::parse('2026-03-02 10:00:00', 'UTC'),
        $rule,
        CarbonImmutable::parse('2026-03-01 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-03-10 00:00:00', 'UTC'),
    );

    expect(($this->iso)($occurrences))->toBe(['2026-03-02T10:00:00Z', '2026-03-05T10:00:00Z', '2026-03-08T10:00:00Z']);
});

it('keeps a monthly series on the last day of shorter months without overflowing into the next month', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Monthly, 1, null);

    $occurrences = $this->expander->between(
        CarbonImmutable::parse('2027-01-31 09:00:00', 'UTC'),
        $rule,
        CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC'),
        CarbonImmutable::parse('2027-05-01 00:00:00', 'UTC'),
    );

    expect(($this->iso)($occurrences))->toBe(['2027-01-31T09:00:00Z', '2027-02-28T09:00:00Z', '2027-03-31T08:00:00Z', '2027-04-30T08:00:00Z']);
});

it('keeps a yearly series of the 29th of February on the 28th in other years', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Yearly, 1, null);

    $occurrences = $this->expander->between(
        CarbonImmutable::parse('2028-02-29 09:00:00', 'UTC'),
        $rule,
        CarbonImmutable::parse('2029-01-01 00:00:00', 'UTC'),
        CarbonImmutable::parse('2030-01-01 00:00:00', 'UTC'),
    );

    expect(($this->iso)($occurrences))->toBe(['2029-02-28T09:00:00Z']);
});

it('stops a series at its end and lists nothing of a series that ended before the window', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Daily, 1, CarbonImmutable::parse('2026-10-21 21:59:59', 'UTC'));

    expect(($this->iso)($this->expander->between(CarbonImmutable::parse('2026-10-19 08:00:00', 'UTC'), $rule, CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC'), CarbonImmutable::parse('2026-10-26 00:00:00', 'UTC'))))
        ->toBe(['2026-10-19T08:00:00Z', '2026-10-20T08:00:00Z', '2026-10-21T08:00:00Z'])
        ->and($this->expander->between(CarbonImmutable::parse('2026-10-19 08:00:00', 'UTC'), $rule, CarbonImmutable::parse('2026-11-01 00:00:00', 'UTC'), CarbonImmutable::parse('2026-11-08 00:00:00', 'UTC')))
        ->toBe([]);
});

it('reaches a window far behind the start of a daily series without walking every earlier day', function (): void {
    config(['recurrence.max_occurrences' => 10]);
    $rule = new RecurrenceRule(RecurrenceFrequency::Daily, 1, null);

    $occurrences = $this->expander->between(
        CarbonImmutable::parse('2020-01-01 08:00:00', 'UTC'),
        $rule,
        CarbonImmutable::parse('2026-10-19 00:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-21 00:00:00', 'UTC'),
    );

    expect(($this->iso)($occurrences))->toBe(['2026-10-19T07:00:00Z', '2026-10-20T07:00:00Z']);
});

it('never hands out more occurrences than the configured ceiling', function (): void {
    config(['recurrence.max_occurrences' => 5]);
    $rule = new RecurrenceRule(RecurrenceFrequency::Daily, 1, null);

    expect($this->expander->between(CarbonImmutable::parse('2026-01-01 08:00:00', 'UTC'), $rule, CarbonImmutable::parse('2026-01-01 00:00:00', 'UTC'), CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC')))
        ->toHaveCount(5);
});

it('names the occurrence that follows a given one and none behind the end of the series', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Weekly, 2, CarbonImmutable::parse('2026-11-10 22:59:59', 'UTC'));

    $anchor = CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC');

    expect($this->expander->after($anchor, $rule, CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'))?->toIso8601ZuluString())->toBe('2026-11-03T09:00:00Z')
        ->and($this->expander->after($anchor, $rule, CarbonImmutable::parse('2026-11-03 09:00:00', 'UTC')))->toBeNull();
});

it('skips the occurrences a series excludes, both in a window and when it names the next one', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Weekly, 1, null, ['2026-10-20T08:00:00Z', '2026-10-27T09:00:00Z']);

    expect(($this->iso)($this->expander->between(CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'), $rule, CarbonImmutable::parse('2026-10-12 00:00:00', 'UTC'), CarbonImmutable::parse('2026-11-04 00:00:00', 'UTC'))))
        ->toBe(['2026-10-13T08:00:00Z', '2026-11-03T09:00:00Z'])
        ->and($this->expander->after(CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'), $rule, CarbonImmutable::parse('2026-10-13 08:00:00', 'UTC'))?->toIso8601ZuluString())->toBe('2026-11-03T09:00:00Z');
});

it('knows whether a moment is a still open occurrence of a series', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Daily, 1, CarbonImmutable::parse('2026-10-25 22:59:59', 'UTC'), ['2026-10-21T08:00:00Z']);
    $start = CarbonImmutable::parse('2026-10-19 08:00:00', 'UTC');

    expect($this->expander->isOccurrence($start, $rule, CarbonImmutable::parse('2026-10-19 08:00:00', 'UTC')))->toBeTrue()
        ->and($this->expander->isOccurrence($start, $rule, CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC')))->toBeTrue()
        ->and($this->expander->isOccurrence($start, $rule, CarbonImmutable::parse('2026-10-20 08:30:00', 'UTC')))->toBeFalse()
        ->and($this->expander->isOccurrence($start, $rule, CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC')))->toBeFalse()
        ->and($this->expander->isOccurrence($start, $rule, CarbonImmutable::parse('2026-10-18 08:00:00', 'UTC')))->toBeFalse()
        ->and($this->expander->isOccurrence($start, $rule, CarbonImmutable::parse('2026-10-26 08:00:00', 'UTC')))->toBeFalse();
});

it('adds an excluded occurrence once and in UTC to the list a series keeps', function (): void {
    $rule = new RecurrenceRule(RecurrenceFrequency::Daily, 1, null, ['2026-10-21T08:00:00Z']);

    expect($rule->exceptionsWith(CarbonImmutable::parse('2026-10-20 10:00:00', 'Europe/Berlin')))->toBe(['2026-10-20T08:00:00Z', '2026-10-21T08:00:00Z'])
        ->and($rule->exceptionsWith(CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC')))->toBe(['2026-10-21T08:00:00Z']);
});

it('names the next occurrence of a monthly or yearly series from its anchor so that a short month does not shift the series', function (RecurrenceFrequency $frequency, string $anchor, string $current, string $expected): void {
    $rule = new RecurrenceRule($frequency, 1, null);

    expect($this->expander->after(CarbonImmutable::parse($anchor, 'UTC'), $rule, CarbonImmutable::parse($current, 'UTC'))?->toIso8601ZuluString())->toBe($expected);
})->with([
    'monthly after february' => [RecurrenceFrequency::Monthly, '2027-01-31 09:00:00', '2027-02-28 09:00:00', '2027-03-31T08:00:00Z'],
    'yearly after a non leap year' => [RecurrenceFrequency::Yearly, '2028-02-29 09:00:00', '2029-02-28 09:00:00', '2030-02-28T09:00:00Z'],
    'yearly into a leap year' => [RecurrenceFrequency::Yearly, '2028-02-29 09:00:00', '2031-02-28 09:00:00', '2032-02-29T09:00:00Z'],
]);
