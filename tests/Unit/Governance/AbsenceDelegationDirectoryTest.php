<?php

declare(strict_types=1);

use App\Support\Governance\AbsenceDelegationDirectory;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new AbsenceDelegationDirectory;

    $this->absentId = ModelStub::ulid('absent-user');
    $this->delegateId = ModelStub::ulid('delegate-user');
    $this->moment = CarbonImmutable::parse('2026-09-03 12:00:00', 'UTC');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('keeps the delegate lookup inside the bound tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->delegateIdCovering($this->absentId, $this->moment));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('absence_delegations'))->toBeTrue()
        ->and($shape->isScopedToTenant('absence_delegations', (string) $this->tenant->getKey()))->toBeTrue();
});

it('blocks every row when no tenant is bound at all', function (): void {
    AccessContext::forgetTenant();

    $shape = QueryShape::attemptedBy(fn () => $this->directory->delegateIdCovering($this->absentId, $this->moment));

    expect($shape)->not->toBeNull()
        ->and($shape->blocksEveryRow())->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('never reads a delegation that was withdrawn', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->delegateIdCovering($this->absentId, $this->moment));

    expect($shape)->not->toBeNull()
        ->and($shape->hidesSoftDeleted('absence_delegations'))->toBeTrue();
});

it('covers the instant from the start inclusively up to the end exclusively', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->delegateIdCovering($this->absentId, $this->moment));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"starts_at" <= ?')
        ->and($shape->sql)->toContain('"ends_at" > ?')
        ->and($shape->bindings)->toContain('2026-09-03 12:00:00');
});

it('asks only for the delegate column and lets the earliest period win', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->delegateIdCovering($this->absentId, $this->moment));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toStartWith('select "delegate_id" from "absence_delegations"')
        ->and($shape->sql)->toContain('order by "starts_at" asc')
        ->and($shape->sql)->toContain('limit 1')
        ->and($shape->sql)->toContain('"user_id" = ?')
        ->and($shape->hasBinding($this->absentId))->toBeTrue();
});

it('reads the delegators through the delegate column and stays inside the tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->delegatorIdsCovering($this->delegateId, $this->moment));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"delegate_id" = ?')
        ->and($shape->hasBinding($this->delegateId))->toBeTrue()
        ->and($shape->isScopedToTenant('absence_delegations', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('absence_delegations'))->toBeTrue();
});

it('narrows the absence lookup to the candidates it was handed', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->directory->coveredUserIds(
        [$this->absentId, $this->delegateId],
        $this->moment,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"user_id" in (?, ?)')
        ->and($shape->hasBinding($this->absentId))->toBeTrue()
        ->and($shape->hasBinding($this->delegateId))->toBeTrue()
        ->and($shape->isScopedToTenant('absence_delegations', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('absence_delegations'))->toBeTrue();
});

it('reads nothing for an empty candidate list', function (): void {
    $covered = null;

    $shape = QueryShape::attemptedBy(function () use (&$covered): void {
        $covered = $this->directory->coveredUserIds([], $this->moment);
    });

    expect($shape)->toBeNull()
        ->and($covered)->toBe([]);
});
