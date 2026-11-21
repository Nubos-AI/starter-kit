<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\Team;
use App\Support\Authorization\AuthorizationDirectory;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->user = AccessContext::user($this->tenant);
    $this->directory = new AuthorizationDirectory;

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('directory-role'),
        'tenant_id' => $this->tenantId,
    ]);

    $this->team = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('directory-team'),
        'tenant_id' => $this->tenantId,
    ]);

    $this->connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "role_assignments"') => [[
            'scope_id' => ModelStub::ulid('directory-scoping-team'),
            'role_id' => ModelStub::ulid('directory-role'),
        ]],
        default => [],
    });

    $this->queriesTo = fn (string $table): array => array_values(array_map(
        static fn (array $query): QueryShape => new QueryShape($query['sql'], $query['bindings']),
        array_filter(
            $this->connection->queries,
            static fn (array $query): bool => str_contains($query['sql'], "from \"{$table}\""),
        ),
    ));
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('reads every tenant owned authorization row only inside the bound tenant', function (Closure $read, string $table): void {
    $read->call($this);

    $shapes = ($this->queriesTo)($table);

    expect($shapes)->not->toBeEmpty();

    foreach ($shapes as $shape) {
        expect($shape->isScopedToTenant($table, $this->tenantId))->toBeTrue();
    }
})->with([
    'permission ids of a role' => [fn (): array => $this->directory->permissionIdsOfRole($this->role), 'permissions'],
    'permissions by identifier' => [fn (): mixed => $this->directory->permissionsByIds([ModelStub::ulid('directory-permission')]), 'permissions'],
    'existing permission ids' => [fn (): array => $this->directory->existingPermissionIds([ModelStub::ulid('directory-permission')]), 'permissions'],
    'field grants of a role' => [fn (): array => $this->directory->fieldGrantsOfRole($this->role), 'field_permissions'],
    'field grants of fields' => [fn (): mixed => $this->directory->fieldGrantsOfFields([ModelStub::ulid('directory-field')]), 'field_permissions'],
    'restricted field ids' => [fn (): array => $this->directory->restrictedFieldIds(), 'field_permissions'],
    'field definitions by identifier' => [fn (): mixed => $this->directory->fieldDefinitionsByIds([ModelStub::ulid('directory-field')]), 'field_definitions'],
    'roles by identifier' => [fn (): mixed => $this->directory->rolesByIds([ModelStub::ulid('directory-role')]), 'roles'],
    'roles assigned to teams' => [fn (): mixed => $this->directory->rolesAssignedToTeams([ModelStub::ulid('directory-team')]), 'roles'],
    'denied permissions of a user' => [fn (): array => $this->directory->deniedPermissionIdsOf($this->user), 'permission_overrides'],
    'denied permissions of a team' => [fn (): array => $this->directory->deniedPermissionIdsOf($this->team), 'permission_overrides'],
]);

it('reads the teams scoping the assignments of a user only inside the tenant of that user and never a trashed team', function (): void {
    $this->directory->teamsScopingAssignmentsOf($this->user);

    $shapes = ($this->queriesTo)('teams');

    expect($shapes)->toHaveCount(1)
        ->and($shapes[0]->sql)->toContain('"tenant_id" = ?')
        ->and($shapes[0]->sql)->toContain('"deleted_at" is null')
        ->and($shapes[0]->hasBinding($this->tenantId))->toBeTrue()
        ->and($shapes[0]->isKeyedTo('teams', ModelStub::ulid('directory-scoping-team')))->toBeTrue();
});
