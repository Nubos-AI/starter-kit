<?php

declare(strict_types=1);

use App\DTOs\Engine\RecordTreeNode;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RollupDebounceStarter;
use App\Support\Engine\RollupOwnerStarter;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = (string) AccessContext::tenant('owner-filter-tenant')->getKey();
    $this->ownerTypeId = ModelStub::ulid('owner-filter-owner-type');
    $this->itemsRelationshipId = ModelStub::ulid('owner-filter-items-relationship');
    $this->notesRelationshipId = ModelStub::ulid('owner-filter-notes-relationship');

    $this->field = fn (string $key, FieldType $type, array $config = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("owner-filter-field-{$key}"),
        'object_type_id' => $this->ownerTypeId,
        'key' => $key,
        'field_type' => $type,
        'config' => $config,
    ]);

    $this->owner = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('owner-filter-owner'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->ownerTypeId,
    ]);

    $this->lookup = new StaticObjectTypeFieldLookup;
    app()->instance(ObjectTypeFieldLookup::class, $this->lookup);

    $this->started = new stdClass;
    $this->started->recordIds = [];

    $starter = Mockery::mock(RollupDebounceStarter::class);
    $starter->shouldReceive('startOwn')->andReturnUsing(function (string $tenantId, string $objectTypeId, string $recordId): void {
        $this->started->recordIds[] = $recordId;
    });
    app()->instance(RollupDebounceStarter::class, $starter);

    $this->ownerStarter = app(RollupOwnerStarter::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('starts no own recompute for an owner whose object type carries no roll-up', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [($this->field)('name', FieldType::TextShort)]);

    $this->ownerStarter->startForLinkOwner($this->owner, $this->itemsRelationshipId);

    expect($this->started->recordIds)->toBe([]);
});

it('starts the own recompute for an owner that carries a roll-up', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [
        ($this->field)('name', FieldType::TextShort),
        ($this->field)('items_total', FieldType::Rollup, ['relationship_type_id' => $this->itemsRelationshipId]),
    ]);

    $this->ownerStarter->startForLinkOwner($this->owner, $this->itemsRelationshipId);

    expect($this->started->recordIds)->toBe([(string) $this->owner->getKey()]);
});

it('starts no own recompute for a link owner whose roll-ups aggregate over another relationship', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [
        ($this->field)('items_total', FieldType::Rollup, ['relationship_type_id' => $this->itemsRelationshipId]),
    ]);

    $this->ownerStarter->startForLinkOwner($this->owner, $this->notesRelationshipId);

    expect($this->started->recordIds)->toBe([]);
});

it('starts the own recompute for a link owner whose roll-up aggregates over the linked relationship', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [
        ($this->field)('items_total', FieldType::Rollup, ['relationship_type_id' => $this->itemsRelationshipId]),
    ]);

    $this->ownerStarter->startForLinkOwner($this->owner, $this->itemsRelationshipId);

    expect($this->started->recordIds)->toBe([(string) $this->owner->getKey()]);
});

it('starts the own recompute for a link owner whose roll-up aggregates over every relationship', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [
        ($this->field)('children_count', FieldType::Rollup, ['aggregate' => 'count']),
    ]);

    $this->ownerStarter->startForLinkOwner($this->owner, $this->notesRelationshipId);

    expect($this->started->recordIds)->toBe([(string) $this->owner->getKey()]);
});

it('starts the own recompute when the owner belongs to a tenant other than the bound one', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [($this->field)('name', FieldType::TextShort)]);
    $foreignOwner = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('owner-filter-foreign-owner'),
        'tenant_id' => ModelStub::ulid('owner-filter-foreign-tenant'),
        'object_type_id' => $this->ownerTypeId,
    ]);

    $this->ownerStarter->startForLinkOwner($foreignOwner, $this->itemsRelationshipId);

    expect($this->started->recordIds)->toBe([(string) $foreignOwner->getKey()]);
});

it('starts the own recompute when no tenant is bound to resolve the field set', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [($this->field)('name', FieldType::TextShort)]);
    AccessContext::forgetTenant();

    $this->ownerStarter->startForLinkOwner($this->owner, $this->itemsRelationshipId);

    expect($this->started->recordIds)->toBe([(string) $this->owner->getKey()]);
});

it('starts the own recompute when the field set of the object type resolves empty', function (): void {
    $this->ownerStarter->startForLinkOwner($this->owner, $this->notesRelationshipId);

    expect($this->started->recordIds)->toBe([(string) $this->owner->getKey()]);
});

it('starts no own recompute for an ancestor whose object type carries no roll-up', function (): void {
    $this->lookup->withFields($this->ownerTypeId, [($this->field)('name', FieldType::TextShort)]);

    app(AncestorChainNotifier::class)->notify($this->tenantId, [
        new RecordTreeNode(recordId: (string) $this->owner->getKey(), objectTypeId: $this->ownerTypeId, depth: 1),
    ]);

    expect($this->started->recordIds)->toBe([]);
});
