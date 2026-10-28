<?php

declare(strict_types=1);

use App\Exceptions\Governance\OverlapCheckOutsideTransactionException;
use App\Support\Governance\AbsenceOverlapGuard;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->guard = new AbsenceOverlapGuard;

    $this->userId = ModelStub::ulid('absent-user');
    $this->startsAt = CarbonImmutable::parse('2026-04-01 00:00:00', 'UTC');
    $this->endsAt = CarbonImmutable::parse('2026-04-08 00:00:00', 'UTC');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses the overlap check outside a transaction and names the user it was asked about', function (): void {
    try {
        $this->guard->assertNoOverlap((string) $this->tenant->getKey(), $this->userId, $this->startsAt, $this->endsAt);
    } catch (OverlapCheckOutsideTransactionException $exception) {
        expect($exception->getMessage())->toContain($this->userId);

        return;
    }

    $this->fail('the guard ran the overlap check without a surrounding transaction');
});

it('takes no lock and reads no row before it has refused the missing transaction', function (): void {
    $shape = QueryShape::attemptedBy(function (): void {
        try {
            $this->guard->assertNoOverlap((string) $this->tenant->getKey(), $this->userId, $this->startsAt, $this->endsAt);
        } catch (OverlapCheckOutsideTransactionException) {
            return;
        }
    });

    expect($shape)->toBeNull();
});
