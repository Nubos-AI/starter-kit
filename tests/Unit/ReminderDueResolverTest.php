<?php

declare(strict_types=1);

use App\Support\Reminders\ReminderDueResolver;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->resolver = new ReminderDueResolver;
    $this->now = CarbonImmutable::parse('2026-07-15 10:30:00');
});

test('absolute mode returns the configured instant', function (): void {
    $due = $this->resolver->resolve(
        ['mode' => 'absolute', 'at' => '2026-08-01T09:00:00'],
        $this->now,
    );

    expect($due?->toDateTimeString())->toBe('2026-08-01 09:00:00');
});

test('relative offset after the trigger adds the interval', function (): void {
    $due = $this->resolver->resolve(
        ['mode' => 'relative', 'offset' => ['value' => 3, 'unit' => 'days', 'direction' => 'after']],
        $this->now,
    );

    expect($due?->toDateTimeString())->toBe('2026-07-18 10:30:00');
});

test('relative offset before the trigger subtracts the interval', function (): void {
    $due = $this->resolver->resolve(
        ['mode' => 'relative', 'offset' => ['value' => 2, 'unit' => 'hours', 'direction' => 'before']],
        $this->now,
    );

    expect($due?->toDateTimeString())->toBe('2026-07-15 08:30:00');
});

test('relative offset honours an explicit time of day', function (): void {
    $due = $this->resolver->resolve(
        [
            'mode' => 'relative',
            'offset' => ['value' => 1, 'unit' => 'weeks', 'direction' => 'after'],
            'time_of_day' => '09:15',
        ],
        $this->now,
    );

    expect($due?->toDateTimeString())->toBe('2026-07-22 09:15:00');
});

test('months unit resolves on the calendar', function (): void {
    $due = $this->resolver->resolve(
        ['mode' => 'relative', 'offset' => ['value' => 1, 'unit' => 'months', 'direction' => 'after']],
        $this->now,
    );

    expect($due?->toDateTimeString())->toBe('2026-08-15 10:30:00');
});

test('empty or malformed spec resolves to null', function (): void {
    expect($this->resolver->resolve([], $this->now))->toBeNull();
    expect($this->resolver->resolve(['mode' => 'absolute'], $this->now))->toBeNull();
    expect($this->resolver->resolve(['mode' => 'relative'], $this->now))->toBeNull();
    expect($this->resolver->resolve(['mode' => 'relative', 'offset' => ['value' => 1, 'unit' => 'aeons', 'direction' => 'after']], $this->now))->toBeNull();
});
