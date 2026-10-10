<?php

declare(strict_types=1);

use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('ships exactly the five documented report keys', function (): void {
    /** @var array<string, mixed> $reports */
    $reports = config('reports');

    expect(array_keys($reports))->toEqualCanonicalizing([
        'k_anonymity_threshold',
        'max_categories',
        'timezone',
        'goal_progress_interval',
        'index_maintenance',
    ]);
});

it('keeps the anonymity threshold and the category cap as positive integers', function (): void {
    expect(config('reports.k_anonymity_threshold'))->toBeInt()
        ->toBeGreaterThan(0)
        ->and(config('reports.max_categories'))->toBeInt()
        ->toBeGreaterThan(0);
});

it('names a real time zone for the report clock', function (): void {
    expect(in_array((string) config('reports.timezone'), DateTimeZone::listIdentifiers(), true))->toBeTrue();
});

it('keeps index maintenance off in the test environment through a real boolean switch', function (): void {
    expect(config('reports.index_maintenance.enabled'))->toBeBool()->toBeFalse();
});

it('carries no cache switch at any depth of the report configuration', function (): void {
    /** @var array<string, mixed> $reports */
    $reports = config('reports');

    $flattened = [];

    array_walk_recursive($reports, static function (mixed $value, string $key) use (&$flattened): void {
        $flattened[] = $key;
    });

    foreach ($flattened as $key) {
        expect($key)->not->toContain('cache');
    }

    expect(json_encode($reports))->not->toContain('cache');
});

it('keeps index maintenance as the only nested key and the four others scalar', function (): void {
    /** @var array<string, mixed> $reports */
    $reports = config('reports');

    foreach ($reports as $key => $value) {
        $key === 'index_maintenance'
            ? expect($value)->toBeArray()
            : expect($value)->not->toBeArray();
    }
});

it('gives the index maintenance block its documented retry shape', function (): void {
    /** @var array<string, mixed> $maintenance */
    $maintenance = config('reports.index_maintenance');

    expect(array_keys($maintenance))->toEqualCanonicalizing(['enabled', 'start_to_close', 'retry'])
        ->and(array_keys($maintenance['retry']))->toEqualCanonicalizing([
            'max_attempts',
            'initial_interval',
            'backoff_coefficient',
            'maximum_interval',
        ])
        ->and($maintenance['start_to_close'])->toBeInt()
        ->and($maintenance['retry']['max_attempts'])->toBeInt()
        ->and($maintenance['retry']['backoff_coefficient'])->toBeFloat();
});
