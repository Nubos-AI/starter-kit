<?php

declare(strict_types=1);

use App\Actions\Authorization\InstallModulePermissionsAction;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    config(['permissions.groups.widgets' => ['tab' => 'operations', 'label' => 'Widgets', 'actions' => ['manage', 'reset']]]);

    $this->tenantIds = [ModelStub::ulid('first-tenant'), ModelStub::ulid('second-tenant')];
    $this->administratorRoleId = ModelStub::ulid('administrator-role');

    /** @var callable(list<string>, list<string>, list<array<string, mixed>>):StaticQueryConnection */
    $this->answering = fn (array $tenantIds, array $existingPermissions, array $roles): StaticQueryConnection => StaticQueryConnection::install(
        function (string $sql, array $bindings) use ($tenantIds, $existingPermissions, $roles): array {
            if (str_contains($sql, 'from "tenants"')) {
                return array_map(static fn (string $id): array => ['id' => $id, 'name' => $id], $tenantIds);
            }

            if (str_contains($sql, 'from "roles"')) {
                return $roles;
            }

            if (str_contains($sql, 'from "permissions"')) {
                $name = collect($bindings)->first(static fn (mixed $binding): bool => is_string($binding) && str_starts_with($binding, 'widgets.'));

                return in_array($name, $existingPermissions, true)
                    ? [['id' => ModelStub::ulid((string) $name), 'name' => $name, 'group' => 'widgets', 'scope' => 'tenant', 'is_system' => true]]
                    : [];
            }

            return [];
        },
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('grants the module permissions to every role that administers roles', function (): void {
    $connection = ($this->answering)([$this->tenantIds[0]], ['widgets.manage', 'widgets.reset'], [
        ['id' => $this->administratorRoleId, 'tenant_id' => $this->tenantIds[0], 'name' => 'administrator'],
    ]);

    app(InstallModulePermissionsAction::class)->execute('widgets');

    $roleQueries = array_values(array_filter($connection->queries, static fn (array $query): bool => str_contains($query['sql'], 'from "roles"')));
    $grants = array_values(array_filter($connection->writtenStatements, static fn (array $write): bool => str_contains($write['sql'], 'insert into "role_permission"')));

    expect($roleQueries)->toHaveCount(1)
        ->and($roleQueries[0]['sql'])->toContain('exists (select * from "permissions" inner join "role_permission"')
        ->and($roleQueries[0]['bindings'])->toContain('roles.update')
        ->and(array_column($grants, 'bindings'))->toBe([
            [ModelStub::ulid('widgets.manage'), $this->administratorRoleId, $this->tenantIds[0]],
            [ModelStub::ulid('widgets.reset'), $this->administratorRoleId, $this->tenantIds[0]],
        ]);
});

it('grants nothing when no role administers roles', function (): void {
    $connection = ($this->answering)([$this->tenantIds[0]], ['widgets.manage', 'widgets.reset'], []);

    app(InstallModulePermissionsAction::class)->execute('widgets');

    expect($connection->writtenSqlOf('role_permission'))->toBe([]);
});

it('creates each missing permission of the group in its own group', function (): void {
    $connection = ($this->answering)([$this->tenantIds[0]], [], []);

    app(InstallModulePermissionsAction::class)->execute('widgets');

    $inserts = array_values(array_filter($connection->writtenStatements, static fn (array $write): bool => str_contains($write['sql'], 'insert into "permissions"')));

    expect($inserts)->toHaveCount(2)
        ->and($inserts[0]['bindings'])->toContain('widgets.manage', 'widgets', $this->tenantIds[0])
        ->and($inserts[1]['bindings'])->toContain('widgets.reset', 'widgets', $this->tenantIds[0]);
});

it('installs the permissions in every tenant', function (): void {
    $connection = ($this->answering)($this->tenantIds, ['widgets.manage', 'widgets.reset'], []);

    app(InstallModulePermissionsAction::class)->execute('widgets');

    $roleQueries = array_values(array_filter($connection->queries, static fn (array $query): bool => str_contains($query['sql'], 'from "roles"')));

    expect($roleQueries)->toHaveCount(2)
        ->and($roleQueries[0]['bindings'])->toContain($this->tenantIds[0])
        ->and($roleQueries[1]['bindings'])->toContain($this->tenantIds[1]);
});

it('refuses a group the permission catalog does not know before touching the database', function (): void {
    $connection = ($this->answering)($this->tenantIds, [], []);

    expect(fn () => app(InstallModulePermissionsAction::class)->execute('unknown-group'))
        ->toThrow(LogicException::class)
        ->and($connection->queries)->toBe([]);
});
