<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\TeamRoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->authorityRole = fn (string $seed, RoleAuthority $authority): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'authority' => $authority->value,
    ], ['permissions' => ModelStub::collection(Permission::class, [])]);
});

afterEach(function (): void {
    Auth::forgetUser();
    AccessContext::forgetTeam();
    AccessContext::forgetTenant();
});

it('never counts an authority that reaches the user only through the active team, just like the permission resolver', function (RoleAuthority $authority): void {
    $team = TeamRoleHolder::make(['tenant_id' => $this->tenant->getKey()], [($this->authorityRole)('team-admin', $authority)]);
    app()->instance('current_team', $team);

    $user = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()]));

    expect($user->isEscalatedAuthority())->toBeFalse()
        ->and(app(PermissionResolver::class)->verdictFor($user, 'roles.update'))->toBeNull();
})->with([RoleAuthority::SuperAdmin, RoleAuthority::ScopeAdmin]);

it('counts an authority assigned to the user directly', function (RoleAuthority $authority): void {
    $user = AccessContext::actAs(RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [($this->authorityRole)('own-admin', $authority)]));

    expect($user->isEscalatedAuthority())->toBeTrue()
        ->and(app(PermissionResolver::class)->verdictFor($user, 'roles.update'))->toBeTrue();
})->with([RoleAuthority::SuperAdmin, RoleAuthority::ScopeAdmin]);
