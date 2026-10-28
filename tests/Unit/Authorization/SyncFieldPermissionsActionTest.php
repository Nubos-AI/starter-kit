<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncFieldPermissionsAction;
use App\Models\Role;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    Auth::forgetUser();

    app()->instance(AdminArtifactAuditor::class, Mockery::spy(AdminArtifactAuditor::class));

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('team-lead'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'team-lead',
    ]);

    $this->fieldId = ModelStub::ulid('company-name');

    /** @var callable(?array{can_read: bool, can_write: bool}):StaticQueryConnection */
    $this->connectionHolding = function (?array $restriction): StaticQueryConnection {
        $connection = StaticQueryConnection::install(
            fn (string $sql, array $bindings): array => match (true) {
                str_contains($sql, 'count(*) as "aggregate"') => [['aggregate' => 1]],
                str_contains($sql, 'from "field_permissions"') && $restriction !== null => [[
                    'id' => ModelStub::ulid('team-lead-company-name'),
                    'tenant_id' => (string) $this->tenant->getKey(),
                    'role_id' => (string) $this->role->getKey(),
                    'field_definition_id' => $this->fieldId,
                    ...$restriction,
                ]],
                default => [],
            },
            static fn (string $sql, array $bindings): int => 1,
        );

        Validator::setPresenceVerifier(new DatabasePresenceVerifier($connection));

        return $connection;
    };

    /** @var callable(bool, bool):void */
    $this->sync = fn (bool $read, bool $write): mixed => app(SyncFieldPermissionsAction::class)->execute($this->role, [[
        'field_definition_id' => $this->fieldId,
        'can_read' => $read,
        'can_write' => $write,
    ]]);

    /** @var callable(StaticQueryConnection):list<mixed> */
    $this->insertedBindings = fn (StaticQueryConnection $connection): array => collect($connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'insert into "field_permissions"'))['bindings'] ?? [];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('removes the restriction once the role may read and write the field again', function (): void {
    $connection = ($this->connectionHolding)(['can_read' => false, 'can_write' => false]);

    ($this->sync)(true, true);

    expect($connection->writtenSqlOf('field_permissions'))->toHaveCount(1)
        ->and($connection->writtenSqlOf('field_permissions')[0])->toStartWith('delete from "field_permissions"');
});

it('writes nothing when an unrestricted field is saved fully open', function (): void {
    $connection = ($this->connectionHolding)(null);

    ($this->sync)(true, true);

    expect($connection->writtenStatements)->toBe([]);
});

it('stores a restriction that withholds writing alone', function (): void {
    $connection = ($this->connectionHolding)(null);

    ($this->sync)(true, false);

    expect(($this->insertedBindings)($connection))->toContain(true)
        ->and(($this->insertedBindings)($connection))->toContain(false);
});

it('never stores writing for a role that may not read the field', function (): void {
    $connection = ($this->connectionHolding)(null);

    ($this->sync)(false, true);

    expect(($this->insertedBindings)($connection))->toContain(false)
        ->and(($this->insertedBindings)($connection))->not->toContain(true);
});
