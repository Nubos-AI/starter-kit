<?php

declare(strict_types=1);

use App\Support\Tenancy\ArtifactPurgeOrder;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

test('full() returns config engine.tenant_content unchanged, with no second, drifting source', function (): void {
    $fromConfig = config('engine.tenant_content');

    expect($fromConfig)->toBeArray()->not->toBeEmpty();

    expect(app(ArtifactPurgeOrder::class)->full())->toBe($fromConfig);
});

test('preservingUserAccess() removes exactly the six access-carrying tables from full(), nothing more and nothing less', function (): void {
    $excludedTables = [
        'users',
        'teams',
        'team_user',
        'sessions',
        'personal_access_tokens',
        'password_reset_tokens',
    ];

    $order = app(ArtifactPurgeOrder::class);
    $full = $order->full();
    $filtered = $order->preservingUserAccess();

    $expected = array_values(array_filter(
        $full,
        static fn (array $entry): bool => !in_array($entry['table'], $excludedTables, true),
    ));

    expect($filtered)->toBe($expected);

    $removedCount = 0;

    foreach ($full as $entry) {
        if (in_array($entry['table'], $excludedTables, true)) {
            $removedCount++;

            expect($filtered)->not->toContain($entry);

            continue;
        }

        expect($filtered)->toContain($entry);
    }

    expect($removedCount)->toBeGreaterThan(0)
        ->and(count($filtered))->toBe(count($full) - $removedCount);
});
