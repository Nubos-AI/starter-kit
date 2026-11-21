<?php

declare(strict_types=1);

use App\Contracts\Engine\RecordBackfillStrategyInterface;
use App\Support\Engine\RecordBackfillStrategyRegistry;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(string):RecordBackfillStrategyInterface */
    $this->register = function (string $reportedKind): RecordBackfillStrategyInterface {
        $strategy = Mockery::mock(RecordBackfillStrategyInterface::class);
        $strategy->shouldReceive('kind')->andReturn($reportedKind);
        app()->instance($strategy::class, $strategy);
        config(['engine.backfill.strategies.probe_kind' => $strategy::class]);

        return $strategy;
    };
});

it('resolves a backfill kind that a module registered under its own key', function (): void {
    $strategy = ($this->register)('probe_kind');

    expect(app(RecordBackfillStrategyRegistry::class)->for('probe_kind'))->toBe($strategy);
});

it('refuses a strategy that reports another kind than the one it is registered under', function (): void {
    ($this->register)('other_kind');

    app(RecordBackfillStrategyRegistry::class)->for('probe_kind');
})->throws(InvalidArgumentException::class, 'The record backfill strategy registered for kind [probe_kind] reports kind [other_kind].');

it('refuses a backfill kind nobody registered', function (): void {
    app(RecordBackfillStrategyRegistry::class)->for('unknown_kind');
})->throws(InvalidArgumentException::class, 'No record backfill strategy is registered for kind [unknown_kind].');
