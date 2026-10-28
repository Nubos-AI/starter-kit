<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Role;
use App\Support\Authorization\RoleAuthorityGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(string, array<string, mixed>):Role */
    $this->roleWith = fn (string $seed, array $attributes = []): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        ...$attributes,
    ]);

    /** @var callable(list<Role>):RoleHolder */
    $this->actor = fn (array $roles = []): RoleHolder => RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        $roles,
    );

    $this->scopeAdmin = ($this->roleWith)('scope-admin-role', ['authority' => RoleAuthority::ScopeAdmin->value]);
    $this->superAdmin = ($this->roleWith)('super-admin-role', ['authority' => RoleAuthority::SuperAdmin->value]);

    $this->guard = new RoleAuthorityGuard;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('ignores a payload that does not mention the authority at all', function (): void {
    $this->guard->assertMayChange(($this->actor)(), ['name' => 'Sales'], RoleAuthority::SuperAdmin);
})->throwsNoExceptions();

it('lets an unchanged authority through without escalation', function (): void {
    $this->guard->assertMayChange(
        ($this->actor)(),
        ['authority' => RoleAuthority::SuperAdmin->value],
        RoleAuthority::SuperAdmin,
    );
})->throwsNoExceptions();

it('refuses a role manager without escalation who raises the authority', function (): void {
    expect(fn () => $this->guard->assertMayChange(
        ($this->actor)(),
        ['authority' => RoleAuthority::ScopeAdmin->value],
        null,
    ))->toThrow(AuthorizationException::class);
});

it('refuses a role manager without escalation who strips the authority', function (): void {
    expect(fn () => $this->guard->assertMayChange(
        ($this->actor)(),
        ['authority' => null],
        RoleAuthority::SuperAdmin,
    ))->toThrow(AuthorizationException::class);
});

it('reserves the unrestricted authority for an actor who holds it', function (): void {
    expect(fn () => $this->guard->assertMayChange(
        ($this->actor)([$this->scopeAdmin]),
        ['authority' => RoleAuthority::SuperAdmin->value],
        null,
    ))->toThrow(AuthorizationException::class);
});

it('lets a scope admin hand out its own authority level', function (): void {
    $this->guard->assertMayChange(
        ($this->actor)([$this->scopeAdmin]),
        ['authority' => RoleAuthority::ScopeAdmin->value],
        null,
    );
})->throwsNoExceptions();

it('lets a super admin grant the unrestricted authority', function (): void {
    $this->guard->assertMayChange(
        ($this->actor)([$this->superAdmin]),
        ['authority' => RoleAuthority::SuperAdmin->value],
        null,
    );
})->throwsNoExceptions();

it('refuses subteam visibility to an actor without escalation', function (): void {
    expect(fn () => $this->guard->assertMayChangeSubteamVisibility(
        ($this->actor)(),
        ['grants_subteam_visibility' => true],
        false,
    ))->toThrow(AuthorizationException::class);
});

it('lets an actor without escalation resend the unchanged subteam visibility', function (): void {
    $this->guard->assertMayChangeSubteamVisibility(
        ($this->actor)(),
        ['grants_subteam_visibility' => true],
        true,
    );
})->throwsNoExceptions();

it('lets an escalated actor grant subteam visibility', function (): void {
    $this->guard->assertMayChangeSubteamVisibility(
        ($this->actor)([$this->scopeAdmin]),
        ['grants_subteam_visibility' => true],
        false,
    );
})->throwsNoExceptions();
