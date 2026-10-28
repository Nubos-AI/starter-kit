<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\User;
use App\Traits\Engine\GuardsOwnerAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rules\Exists;
use Mockery\MockInterface;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->guard = new class
    {
        use GuardsOwnerAssignment;

        public function assign(?User $user): void
        {
            $this->guardOwnerAssignment($user);
        }

        public function rule(string $tenantId): Exists
        {
            return $this->ownerRule($tenantId);
        }
    };

    /** @var callable(RoleAuthority ...):User */
    $this->actor = function (RoleAuthority ...$authorities): User {
        /** @var User&MockInterface $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRoleWithAuthority')
            ->andReturnUsing(static fn (RoleAuthority $authority): bool => in_array($authority, $authorities, true));

        return $user;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance('current_automation_actor');
});

it('refuses to hand a record to another owner for a plain editor', function (): void {
    expect(fn () => $this->guard->assign(($this->actor)()))
        ->toThrow(AuthorizationException::class);
});

it('lets a scope admin reassign the owner', function (): void {
    $this->guard->assign(($this->actor)(RoleAuthority::ScopeAdmin));
})->throwsNoExceptions();

it('lets a super admin reassign the owner', function (): void {
    $this->guard->assign(($this->actor)(RoleAuthority::SuperAdmin));
})->throwsNoExceptions();

it('lets a caller without an acting user through so background writes keep working', function (): void {
    $this->guard->assign(null);
})->throwsNoExceptions();

it('lets an automation act on behalf of the tenant without an admin role', function (): void {
    app()->instance('current_automation_actor', ['id' => ModelStub::ulid('automation')]);

    $this->guard->assign(($this->actor)());
})->throwsNoExceptions();

it('ignores an automation marker that carries no identifier', function (): void {
    app()->instance('current_automation_actor', ['id' => '']);

    expect(fn () => $this->guard->assign(($this->actor)()))
        ->toThrow(AuthorizationException::class);
});

it('accepts only a live user of the record tenant as the new owner', function (): void {
    $rule = (string) $this->guard->rule((string) $this->tenant->getKey());

    expect($rule)->toStartWith('exists:users,id,')
        ->and($rule)->toContain('tenant_id,"'.(string) $this->tenant->getKey().'"')
        ->and($rule)->toContain('deleted_at,"NULL"');
});
