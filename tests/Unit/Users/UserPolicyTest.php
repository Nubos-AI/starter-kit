<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Policies\Users\UserPolicy;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    FakeManagedUserResolver::reachingTheTenant()->managing([ModelStub::ulid('super-admin')]);

    $this->superAdmin = RoleHolder::make(
        ['tenant_id' => (string) $this->tenant->getKey(), 'is_service' => false],
        [ModelStub::make(Role::class, ['authority' => RoleAuthority::SuperAdmin])],
        'super-admin',
    );

    $this->plainManager = RoleHolder::make(
        ['tenant_id' => (string) $this->tenant->getKey(), 'is_service' => false],
        [ModelStub::make(Role::class, ['authority' => null])],
        'plain-manager',
    );

    $this->escalatedManager = RoleHolder::make(
        ['tenant_id' => (string) $this->tenant->getKey(), 'is_service' => false],
        [ModelStub::make(Role::class, ['authority' => RoleAuthority::ScopeAdmin])],
        'escalated-manager',
    );

    $this->policy = fn (): UserPolicy => app(UserPolicy::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a manager without authority to block a super admin', function (): void {
    expect(($this->policy)()->block($this->plainManager, $this->superAdmin))->toBeFalse();
});

it('refuses a manager without authority to delete a super admin', function (): void {
    expect(($this->policy)()->delete($this->plainManager, $this->superAdmin))->toBeFalse();
});

it('lets an escalated manager block and delete a super admin they manage', function (): void {
    expect(($this->policy)()->block($this->escalatedManager, $this->superAdmin))->toBeTrue()
        ->and(($this->policy)()->delete($this->escalatedManager, $this->superAdmin))->toBeTrue();
});
