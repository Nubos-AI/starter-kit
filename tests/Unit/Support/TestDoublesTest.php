<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('tells the transaction depth and keeps what a rolled back transaction wrote apart', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $levels = [];

    expect(function () use (&$levels): void {
        DB::transaction(static function () use (&$levels): void {
            $levels[] = DB::transactionLevel();
            DB::transaction(static function () use (&$levels): void {
                $levels[] = DB::transactionLevel();
            });
            DB::statement('delete from "notes"');

            throw new RuntimeException('abort');
        });
    })->toThrow(RuntimeException::class, 'abort');

    expect($levels)->toBe([1, 2])
        ->and(DB::transactionLevel())->toBe(0)
        ->and($connection->openedTransactions)->toBe(1)
        ->and(array_column($connection->rolledBackStatements, 'sql'))->toBe(['delete from "notes"']);
});

it('treats an insert that returns rows as a write the test has to allow', function (): void {
    StaticQueryConnection::install(static fn (): array => [['id' => 1]]);

    expect(fn (): array => DB::select('insert into "notes" ("body") values (?) returning "id"', ['x']))
        ->toThrow(RuntimeException::class, 'A database free test must never write to a connection.');
});

it('tells which record the gate was asked about', function (): void {
    $tenant = AccessContext::tenant();
    $asked = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('asked'), 'tenant_id' => $tenant->getKey()]);
    $other = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('other'), 'tenant_id' => $tenant->getKey()]);
    $gate = GateSpy::allowing('delete');

    Gate::forUser(AccessContext::user($tenant))->allows('delete', $asked);

    expect($gate->wasAskedFor('delete'))->toBeTrue()
        ->and($gate->wasAskedFor('delete', $asked))->toBeTrue()
        ->and($gate->wasAskedFor('delete', $other))->toBeFalse()
        ->and($gate->wasAskedFor('update', $asked))->toBeFalse();
});

it('finds a partial unique index regardless of case, quoting and whitespace', function (): void {
    $schema = new SchemaShape([
        "create unique index \"idx_notes_body\" on \"notes\" ( \"tenant_id\",  \"body\" )\n where deleted_at IS NULL",
    ]);

    expect($schema->hasPartialUniqueIndex('idx_notes_body', 'notes', ['tenant_id', 'body'], 'deleted_at IS NULL'))->toBeTrue()
        ->and($schema->hasPartialUniqueIndex('idx_notes_body', 'notes', ['body', 'tenant_id'], 'deleted_at IS NULL'))->toBeFalse()
        ->and($schema->hasPartialUniqueIndex('idx_notes_body', 'notes', ['tenant_id', 'body'], 'archived_at IS NULL'))->toBeFalse();
});
