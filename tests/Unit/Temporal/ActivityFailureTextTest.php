<?php

declare(strict_types=1);

use App\Support\Temporal\ActivityFailures;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('keeps the sql, its bindings and the database host of a failed query out of the history', function (): void {
    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('temporal.failures.message_limit')->andReturn(500);

    $driverFailure = new class('SQLSTATE[23505]: Unique violation: duplicate key value (email)=(kunde@example.test)') extends PDOException
    {
        public function __construct(string $message)
        {
            parent::__construct($message);

            $this->code = '23505';
        }
    };

    $failure = new QueryException(
        'pgsql',
        'update "users" set "pending_email" = ? where "id" = ?',
        ['kunde@example.test', '01JTESTUSER'],
        $driverFailure,
        ['host' => 'db-primary.internal', 'port' => '5432', 'database' => 'nubos'],
    );

    $compacted = (new ActivityFailures($config))->compact($failure, true);

    expect($compacted->getOriginalMessage())->toBe('QueryException: SQLSTATE[23505]')
        ->and($compacted->getOriginalMessage())->not->toContain('kunde@example.test')
        ->and($compacted->getOriginalMessage())->not->toContain('db-primary.internal');
});
