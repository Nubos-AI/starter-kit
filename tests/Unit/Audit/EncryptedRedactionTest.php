<?php

declare(strict_types=1);

use App\Support\Engine\RecordDiff;
use Illuminate\Contracts\Config\Repository;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('never lets the plaintext of an encrypted field into the audit diff', function (): void {
    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('engine.diff.redacted_placeholder')->andReturn('[sealed]');

    $changes = (new RecordDiff($config))->between(
        ['ssn' => 'secret-123', 'amount' => 1],
        ['ssn' => 'secret-456', 'amount' => 2],
        ['ssn'],
    );

    expect($changes)->toBe([
        ['field_key' => 'amount', 'old' => 1, 'new' => 2],
        ['field_key' => 'ssn', 'old' => '[sealed]', 'new' => '[sealed]'],
    ]);
});
