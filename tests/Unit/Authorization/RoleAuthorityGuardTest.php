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

    /** @var callable(string):RoleHolder */
    $this->actorHolding = fn (string $role): RoleHolder => ($this->actor)(match ($role) {
        'scope' => [$this->scopeAdmin],
        'super' => [$this->superAdmin],
        default => [],
    });

    $this->guard = new RoleAuthorityGuard;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('decides whether an actor may change the authority of a role', function (string $actorRole, array $payload, ?RoleAuthority $currentAuthority, bool $isRefused): void {
    $refused = false;

    try {
        $this->guard->assertMayChange(($this->actorHolding)($actorRole), $payload, $currentAuthority);
    } catch (AuthorizationException) {
        $refused = true;
    }

    expect($refused)->toBe($isRefused);
})->with([
    'payload without the authority' => ['none', ['name' => 'Sales'], RoleAuthority::SuperAdmin, false],
    'unchanged authority without escalation' => ['none', ['authority' => RoleAuthority::SuperAdmin->value], RoleAuthority::SuperAdmin, false],
    'role manager without escalation raises the authority' => ['none', ['authority' => RoleAuthority::ScopeAdmin->value], null, true],
    'role manager without escalation strips the authority' => ['none', ['authority' => null], RoleAuthority::SuperAdmin, true],
    'scope admin grants the unrestricted authority' => ['scope', ['authority' => RoleAuthority::SuperAdmin->value], null, true],
    'scope admin hands out its own authority level' => ['scope', ['authority' => RoleAuthority::ScopeAdmin->value], null, false],
    'super admin grants the unrestricted authority' => ['super', ['authority' => RoleAuthority::SuperAdmin->value], null, false],
]);

it('decides whether an actor may change the subteam visibility of a role', function (string $actorRole, bool $currentlyGranted, bool $isRefused): void {
    $refused = false;

    try {
        $this->guard->assertMayChangeSubteamVisibility(
            ($this->actorHolding)($actorRole),
            ['grants_subteam_visibility' => true],
            $currentlyGranted,
        );
    } catch (AuthorizationException) {
        $refused = true;
    }

    expect($refused)->toBe($isRefused);
})->with([
    'actor without escalation grants it' => ['none', false, true],
    'actor without escalation resends it unchanged' => ['none', true, false],
    'escalated actor grants it' => ['scope', false, false],
]);
