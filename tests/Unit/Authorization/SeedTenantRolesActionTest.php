<?php

declare(strict_types=1);

use App\Actions\Authorization\SeedTenantRolesAction;
use App\Enums\Authorization\RoleAuthority;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->definitions = fn (): array => collect(app(SeedTenantRolesAction::class)->definitions())
        ->keyBy('name')
        ->all();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('seeds exactly the configured system roles of a tenant', function (): void {
    expect(array_keys(($this->definitions)()))->toBe(['owner', 'admin', 'member']);

    foreach (($this->definitions)() as $definition) {
        expect($definition['system'])->toBeTrue();
    }
});

it('takes the persisted role names from the configuration', function (): void {
    config()->set('permissions.tenant_roles.owner.name', 'inhaber');

    expect(($this->definitions)())->toHaveKey('inhaber')
        ->and(($this->definitions)()['inhaber']['authority'])->toBe(RoleAuthority::ScopeAdmin);
});

it('marks only the owner as escalated, which needs no permission rows', function (): void {
    $definitions = ($this->definitions)();

    expect($definitions['owner']['authority'])->toBe(RoleAuthority::ScopeAdmin)
        ->and($definitions['owner']['permissions'])->toBe([])
        ->and($definitions['admin']['authority'])->toBeNull()
        ->and($definitions['member']['authority'])->toBeNull();
});

it('gives the admin every global permission except the tenant administration', function (): void {
    $permissions = ($this->definitions)()['admin']['permissions'];

    expect($permissions)->toContain('roles.update', 'object-types.create', 'members.invite')
        ->and(array_filter($permissions, static fn (string $name): bool => str_starts_with($name, 'tenants.')))->toBe([]);
});

it('gives the member only the configured read access', function (): void {
    expect(($this->definitions)()['member']['permissions'])->toBe(['organisation.view', 'members.view', 'teams.view']);
});

it('looks the roles up inside the bound tenant only', function (): void {
    $tenant = AccessContext::tenant('registered-tenant');

    $shape = QueryShape::attemptedBy(fn () => app(SeedTenantRolesAction::class)->execute());

    expect($shape)->not->toBeNull()
        ->and($shape->targets('roles'))->toBeTrue()
        ->and($shape->isScopedToTenant('roles', (string) $tenant->getKey()))->toBeTrue();
});
