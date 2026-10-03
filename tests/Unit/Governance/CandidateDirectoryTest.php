<?php

declare(strict_types=1);

use App\Enums\Users\UserStatus;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Governance\CandidateDirectory;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new CandidateDirectory;

    $this->tenantId = (string) $this->tenant->getKey();
    $this->roleId = ModelStub::ulid('role');
    $this->teamId = ModelStub::ulid('record-team');
    $this->candidateId = ModelStub::ulid('candidate');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads role holders only among users and never among teams carrying the same role', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->roleHolderIds([$this->roleId], $this->tenantId, null));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('role_assignments'))->toBeTrue()
        ->and($shape->sql)->toStartWith('select "model_id" from "role_assignments"')
        ->and($shape->sql)->toContain('"model_type" = ?')
        ->and($shape->hasBinding((new User)->getMorphClass()))->toBeTrue()
        ->and($shape->hasBinding((new Team)->getMorphClass()))->toBeFalse()
        ->and($shape->hasBinding($this->roleId))->toBeTrue();
});

it('admits an unscoped and a tenant scoped assignment but no foreign team scope when no team is named', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->roleHolderIds([$this->roleId], $this->tenantId, null));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"scope_id" is null')
        ->and($shape->sql)->toContain('"scope_type" = ?')
        ->and($shape->hasBinding((new Tenant)->getMorphClass()))->toBeTrue()
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding($this->teamId))->toBeFalse();
});

it('opens the team scope branch for exactly the team it was handed', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->roleHolderIds(
        [$this->roleId],
        $this->tenantId,
        $this->teamId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding((new Team)->getMorphClass()))->toBeTrue()
        ->and($shape->hasBinding($this->teamId))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('foreign-team')))->toBeFalse();
});

it('reads no role assignment at all without a role', function (): void {
    $holderIds = null;

    $shape = QueryShape::attemptedBy(function () use (&$holderIds): void {
        $holderIds = $this->directory->roleHolderIds([], $this->tenantId, $this->teamId);
    });

    expect($shape)->toBeNull()
        ->and($holderIds)->toBe([]);
});

it('pins the team lookup to the named tenant and skips a dissolved team', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->memberIdsOfTeams([$this->teamId], $this->tenantId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->hasBinding($this->teamId))->toBeTrue();
});

it('takes the named teams themselves and never their subtree', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->memberIdsOfTeams([$this->teamId], $this->tenantId));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"teams"."id" in (?)')
        ->and($shape->sql)->not->toContain('ancestor_team_ids')
        ->and($shape->sql)->not->toContain('parent_team_id');
});

it('reads no team at all without a team', function (): void {
    $memberIds = null;

    $shape = QueryShape::attemptedBy(function () use (&$memberIds): void {
        $memberIds = $this->directory->memberIdsOfTeams([], $this->tenantId);
    });

    expect($shape)->toBeNull()
        ->and($memberIds)->toBe([]);
});

it('admits only living accepted human accounts of the named tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->activeUsers([$this->candidateId], $this->tenantId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding($this->tenantId))->toBeTrue()
        ->and($shape->sql)->toContain('"is_service" = ?')
        ->and($shape->hasBinding(0))->toBeTrue()
        ->and($shape->sql)->toContain('"status" = ?')
        ->and($shape->hasBinding(UserStatus::Accepted->value))->toBeTrue()
        ->and($shape->hidesSoftDeleted('users'))->toBeTrue();
});

it('keys the candidate lookup to the collected identifiers in a stable order', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->activeUsers(
        [$this->candidateId, ModelStub::ulid('other-candidate')],
        $this->tenantId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"users"."id" in (?, ?)')
        ->and($shape->hasBinding($this->candidateId))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('other-candidate')))->toBeTrue()
        ->and($shape->sql)->toContain('order by "id" asc');
});

it('reads no user at all without a candidate', function (): void {
    $users = null;

    $shape = QueryShape::attemptedBy(function () use (&$users): void {
        $users = $this->directory->activeUsers([], $this->tenantId);
    });

    expect($shape)->toBeNull()
        ->and($users?->all())->toBe([]);
});
