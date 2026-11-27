<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\ActingUserContext;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = ModelStub::ulid('page-tenant');
    $this->personId = ModelStub::ulid('owner');
    StaticQueryConnection::install(fn (string $sql, array $bindings): array => match (true) {
        str_contains($sql, 'from "tenants"') => [['id' => $this->tenantId, 'name' => 'Page tenant']],
        str_contains($sql, 'from "users"') => [['id' => $this->personId, 'tenant_id' => ModelStub::ulid('switched-tenant'), 'status' => 'accepted', 'is_service' => false, 'first_name' => 'Ada', 'last_name' => 'Owner', 'email' => 'ada@firma.de']],
        default => [],
    });
    $this->members = function (array $memberIds): TenantUserIdResolver {
        $resolver = Mockery::mock(TenantUserIdResolver::class);
        $resolver->shouldReceive('resolve')
            ->with($this->tenantId, [$this->personId])
            ->andReturn($memberIds);

        return $resolver;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    Mockery::close();
});

it('acts as a person who switched to another tenant while they are still a member of the tenant the work belongs to and seats them there', function (): void {
    $seen = null;

    (new ActingUserContext(($this->members)([$this->personId])))->run($this->tenantId, $this->personId, function (User $actingUser) use (&$seen): void {
        $seen = [
            'tenant_id' => $actingUser->tenant_id,
            'is_dirty' => $actingUser->isDirty('tenant_id'),
            'auth' => Auth::id(),
            'current_tenant' => app('current_tenant') instanceof Tenant ? app('current_tenant')->getKey() : null,
        ];
    });

    expect($seen)->toBe([
        'tenant_id' => $this->tenantId,
        'is_dirty' => false,
        'auth' => $this->personId,
        'current_tenant' => $this->tenantId,
    ]);
});

it('refuses to act as a person who works in another tenant and is no member of the tenant the work belongs to', function (): void {
    $isRun = false;

    expect(fn () => (new ActingUserContext(($this->members)([])))->run($this->tenantId, $this->personId, function () use (&$isRun): void {
        $isRun = true;
    }))->toThrow(AuthorizationException::class);

    expect($isRun)->toBeFalse();
});
