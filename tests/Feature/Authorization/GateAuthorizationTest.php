<?php

declare(strict_types=1);

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('grants super admin access to everything', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'nubos:super-admin', 'scope' => 'platform']);

    $user->assignRole($role);

    Gate::define('any-ability', fn () => false);

    expect($user->can('any-ability'))->toBeTrue();
});

it('denies access when user has no roles', function (): void {
    $user = User::factory()->create();

    Gate::define('some-ability', fn () => null);

    expect($user->can('some-ability'))->toBeFalse();
});

it('grants access when user has matching permission in scope', function (): void {
    $user = User::factory()->create();
    $scopeModel = User::factory()->create();
    $role = Role::factory()->create(['name' => 'member', 'scope' => 'organization']);
    $permission = Permission::factory()->create(['name' => 'test-ability', 'scope' => 'organization']);

    $role->permissions()->attach($permission);
    $user->assignRole($role, $scopeModel);

    app()->bind(
        \App\Contracts\Authorization\ScopeResolverInterface::class,
        fn () => new class ($scopeModel) implements \App\Contracts\Authorization\ScopeResolverInterface {
            public function __construct(private readonly \Illuminate\Database\Eloquent\Model $scope) {}

            public function resolve(): array
            {
                return [$this->scope];
            }
        },
    );

    expect($user->can('test-ability'))->toBeTrue();
});

it('grants owner access to all abilities within scope', function (): void {
    $user = User::factory()->create();
    $scopeModel = User::factory()->create();
    $role = Role::factory()->create(['name' => 'owner', 'scope' => 'organization']);

    $user->assignRole($role, $scopeModel);

    app()->bind(
        \App\Contracts\Authorization\ScopeResolverInterface::class,
        fn () => new class ($scopeModel) implements \App\Contracts\Authorization\ScopeResolverInterface {
            public function __construct(private readonly \Illuminate\Database\Eloquent\Model $scope) {}

            public function resolve(): array
            {
                return [$this->scope];
            }
        },
    );

    expect($user->can('anything'))->toBeTrue();
});

it('grants admin access to all abilities within scope', function (): void {
    $user = User::factory()->create();
    $scopeModel = User::factory()->create();
    $role = Role::factory()->create(['name' => 'admin', 'scope' => 'organization']);

    $user->assignRole($role, $scopeModel);

    app()->bind(
        \App\Contracts\Authorization\ScopeResolverInterface::class,
        fn () => new class ($scopeModel) implements \App\Contracts\Authorization\ScopeResolverInterface {
            public function __construct(private readonly \Illuminate\Database\Eloquent\Model $scope) {}

            public function resolve(): array
            {
                return [$this->scope];
            }
        },
    );

    expect($user->can('anything'))->toBeTrue();
});

it('denies access when permission is in different scope', function (): void {
    $user = User::factory()->create();
    $scopeA = User::factory()->create();
    $scopeB = User::factory()->create();
    $role = Role::factory()->create(['name' => 'member', 'scope' => 'organization']);
    $permission = Permission::factory()->create(['name' => 'restricted-ability', 'scope' => 'organization']);

    $role->permissions()->attach($permission);
    $user->assignRole($role, $scopeA);

    app()->bind(
        \App\Contracts\Authorization\ScopeResolverInterface::class,
        fn () => new class ($scopeB) implements \App\Contracts\Authorization\ScopeResolverInterface {
            public function __construct(private readonly \Illuminate\Database\Eloquent\Model $scope) {}

            public function resolve(): array
            {
                return [$this->scope];
            }
        },
    );

    expect($user->can('restricted-ability'))->toBeFalse();
});

it('returns null from gate when no scopes are active', function (): void {
    $user = User::factory()->create();
    $role = Role::factory()->create(['name' => 'member', 'scope' => 'organization']);

    $user->assignRole($role);

    Gate::define('fallback-ability', fn () => true);

    expect($user->can('fallback-ability'))->toBeTrue();
});
