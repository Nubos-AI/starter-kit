<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\RecordHierarchyReader;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->carrierId = ModelStub::ulid('carrier');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'hierarchy_relationship_type_id' => $this->carrierId,
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->reader = new RecordHierarchyReader;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks a parent candidate up inside the tenant, inside the object type and among the live rows only', function (): void {
    $parentId = ModelStub::ulid('parent-record');

    $shape = QueryShape::attemptedBy(fn () => $this->reader->parentCandidate($this->record, $parentId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->isKeyedTo('custom_records', $parentId))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('reads the parent edges of the carrier from the incoming end of the link', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->reader->parentIdsOf(
        (string) $this->tenant->getKey(),
        $this->carrierId,
        (string) $this->record->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('record_links'))->toBeTrue()
        ->and($shape->isScopedToTenant('record_links', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"relationship_type_id" = ?')
        ->and($shape->hasBinding($this->carrierId))->toBeTrue()
        ->and($shape->sql)->toContain('"to_record_id" = ?')
        ->and($shape->hasBinding((string) $this->record->getKey()))->toBeTrue()
        ->and($shape->sql)->not->toContain('"from_record_id" =');
});
