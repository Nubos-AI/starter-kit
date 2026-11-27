<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('carries the tenant condition into a lookup by primary key', function (): void {
    $key = ModelStub::ulid('foreign-record');

    $attempt = QueryShape::attemptedBy(static fn (): ?CustomRecord => CustomRecord::query()->find($key));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->isKeyedTo('custom_records', $key))->toBeTrue()
        ->and($attempt?->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('blocks every row instead of leaking one when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(CustomRecord::class)->blocksEveryRow())->toBeTrue()
        ->and(QueryShape::of(ObjectType::class)->blocksEveryRow())->toBeTrue();
});
