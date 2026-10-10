<?php

declare(strict_types=1);

use App\Support\Modules\ModuleExecutionGate;
use Illuminate\Database\DatabaseManager;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(bool):array{gate: ModuleExecutionGate, statements: ArrayObject<int, string>} */
    $this->gateOver = static function (bool $enabled): array {
        $statements = new ArrayObject;

        $connection = Mockery::mock();
        $connection->shouldReceive('select')->andReturnUsing(static function (string $sql) use ($statements): array {
            $statements[] = $sql;

            return [];
        });

        $rows = Mockery::mock();
        $rows->shouldReceive('where')->andReturnSelf();
        $rows->shouldReceive('exists')->andReturn(!$enabled);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturn($connection);
        $database->shouldReceive('table')->with('modules')->andReturn($rows);

        return ['gate' => new ModuleExecutionGate($database), 'statements' => $statements];
    };
});

afterEach(function (): void {
    Mockery::close();
});

it('runs the operation of an enabled module and hands its result back', function (): void {
    $gate = ($this->gateOver)(true)['gate'];

    expect($gate->run('nubos/automations', static fn (): string => 'ran'))->toBe('ran');
});

it('refuses a disabled module and never executes its operation', function (): void {
    $gate = ($this->gateOver)(false)['gate'];
    $executed = false;

    expect(function () use ($gate, &$executed): mixed {
        return $gate->run('nubos/automations', static function () use (&$executed): string {
            $executed = true;

            return 'ran';
        });
    })->toThrow(RuntimeException::class);

    expect($executed)->toBeFalse();
});

it('holds a shared lock around the whole operation and releases it afterwards', function (): void {
    $over = ($this->gateOver)(true);
    $seenDuringOperation = [];

    $over['gate']->run('nubos/automations', static function () use ($over, &$seenDuringOperation): void {
        $seenDuringOperation = $over['statements']->getArrayCopy();
    });

    expect($seenDuringOperation)->toBe(['SELECT pg_advisory_lock_shared(hashtextextended(?, 0))'])
        ->and($over['statements']->getArrayCopy())->toBe([
            'SELECT pg_advisory_lock_shared(hashtextextended(?, 0))',
            'SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))',
        ]);
});

it('releases the shared lock even when the operation throws', function (): void {
    $over = ($this->gateOver)(true);

    expect(fn (): mixed => $over['gate']->run('nubos/automations', static fn (): never => throw new RuntimeException('Temporal unavailable')))
        ->toThrow(RuntimeException::class, 'Temporal unavailable');

    expect($over['statements']->getArrayCopy())->toBe([
        'SELECT pg_advisory_lock_shared(hashtextextended(?, 0))',
        'SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))',
    ]);
});

it('releases the shared lock when the module turns out to be disabled', function (): void {
    $over = ($this->gateOver)(false);

    expect(fn (): mixed => $over['gate']->run('nubos/automations', static fn (): string => 'ran'))
        ->toThrow(RuntimeException::class);

    expect($over['statements']->getArrayCopy())->toBe([
        'SELECT pg_advisory_lock_shared(hashtextextended(?, 0))',
        'SELECT pg_advisory_unlock_shared(hashtextextended(?, 0))',
    ]);
});
