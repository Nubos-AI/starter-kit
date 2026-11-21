<?php

declare(strict_types=1);

use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('builds every activity of the relay workflow through the shared options so no timeout can drift apart', function (): void {
    $source = (string) file_get_contents(app_path('Workflows/Engine/RecordChangeRelayWorkflow.php'));

    $builtActivities = substr_count($source, '->build(');
    $sharedOptions = substr_count($source, 'RecordActivityOptions::make()');

    expect($builtActivities)->toBeGreaterThan(0)
        ->and($sharedOptions)->toBe($builtActivities)
        ->and($source)->not->toContain('withStartToCloseTimeout')
        ->and($source)->not->toContain('AutomationRetryOptions');
});
