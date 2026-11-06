<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Support\Engine\RecordStateConditionEvaluator;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('state-object-type');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('state-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('matches an empty filter definition without ever reaching the database', function (): void {
    $evaluator = app(RecordStateConditionEvaluator::class);

    expect($evaluator->matches($this->record, []))->toBeTrue()
        ->and(QueryShape::attemptedBy(fn (): bool => $evaluator->matches($this->record, [])))->toBeNull();
});

it('resolves the object type of the record under test first for a non-empty filter definition', function (): void {
    $shape = QueryShape::attemptedBy(fn (): bool => app(RecordStateConditionEvaluator::class)->matches(
        $this->record,
        ['combinator' => 'and', 'conditions' => [['field' => 'status', 'operator' => 'equals', 'value' => 'aktiv']]],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape?->targets('object_types'))->toBeTrue()
        ->and($shape?->isKeyedTo('object_types', (string) $this->objectTypeId))->toBeTrue()
        ->and($shape?->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});
