<?php

declare(strict_types=1);

use App\Actions\Engine\LinkRecordRelationAction;
use App\Actions\Engine\LinkRecordsAction;
use App\Actions\Engine\ReparentRecordAction;
use App\Enums\Engine\RelationDirection;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Support\Engine\RecordRelationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->target = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('partner-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    /** @var callable(bool):RelationshipType */
    $this->relationshipType = fn (bool $isHierarchy): RelationshipType => ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('carrier'),
        'tenant_id' => $this->tenant->getKey(),
        'from_object_type_id' => (string) $this->objectType->getKey(),
        'to_object_type_id' => (string) $this->objectType->getKey(),
        'is_hierarchy' => $isHierarchy,
    ]);

    $this->linkRecords = Mockery::mock(LinkRecordsAction::class);
    $this->reparent = Mockery::mock(ReparentRecordAction::class);
    $this->resolver = Mockery::mock(RecordRelationResolver::class);

    $this->link = new LinkRecordRelationAction($this->linkRecords, $this->reparent, $this->resolver);

    /** @var callable(string, string):array<string, mixed> */
    $this->payload = fn (string $direction, ?string $targetId = null): array => [
        'relationship_type_id' => ModelStub::ulid('carrier'),
        'direction' => $direction,
        'target_record_id' => $targetId ?? (string) $this->target->getKey(),
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('rejects an unknown direction before it resolves anything', function (): void {
    $this->resolver->shouldNotReceive('typeFor');
    $this->linkRecords->shouldNotReceive('execute');
    $this->reparent->shouldNotReceive('execute');

    expect(fn () => $this->link->execute($this->user, $this->record, ($this->payload)('sideways')))
        ->toThrow(ValidationException::class);
});

it('rejects a target key that is no ulid before it resolves anything', function (): void {
    $this->resolver->shouldNotReceive('typeFor');
    $this->linkRecords->shouldNotReceive('execute');

    expect(fn () => $this->link->execute($this->user, $this->record, ($this->payload)('outgoing', 'not-a-ulid')))
        ->toThrow(ValidationException::class);
});

it('puts the record on the source end of a plain outgoing relation', function (): void {
    $type = ($this->relationshipType)(false);

    $this->resolver->shouldReceive('typeFor')->once()->andReturn($type);
    $this->resolver->shouldReceive('assertMayViewCounterpart')->once()->with($this->user, $type, RelationDirection::Outgoing);
    $this->resolver->shouldReceive('targetFor')->once()->andReturn($this->target);
    $this->resolver->shouldNotReceive('assertMayReparent');
    $this->reparent->shouldNotReceive('execute');

    $this->linkRecords->shouldReceive('execute')->once()
        ->withArgs(fn (array $attributes): bool => $attributes['from_record_id'] === $this->record->getKey()
            && $attributes['to_record_id'] === $this->target->getKey()
            && $attributes['relationship_type_id'] === $type->getKey()
            && $attributes['position'] === 0)
        ->andReturn(ModelStub::make(RecordLink::class));

    $this->link->execute($this->user, $this->record, ($this->payload)('outgoing'));
});

it('puts the record on the target end of a plain incoming relation', function (): void {
    $type = ($this->relationshipType)(false);

    $this->resolver->shouldReceive('typeFor')->once()->andReturn($type);
    $this->resolver->shouldReceive('assertMayViewCounterpart')->once()->with($this->user, $type, RelationDirection::Incoming);
    $this->resolver->shouldReceive('targetFor')->once()->andReturn($this->target);

    $this->linkRecords->shouldReceive('execute')->once()
        ->withArgs(fn (array $attributes): bool => $attributes['from_record_id'] === $this->target->getKey()
            && $attributes['to_record_id'] === $this->record->getKey())
        ->andReturn(ModelStub::make(RecordLink::class));

    $this->link->execute($this->user, $this->record, ($this->payload)('incoming'));
});

it('hangs the record under the chosen parent for an incoming hierarchy relation', function (): void {
    $type = ($this->relationshipType)(true);

    $this->resolver->shouldReceive('typeFor')->once()->andReturn($type);
    $this->resolver->shouldReceive('assertMayViewCounterpart')->once();
    $this->resolver->shouldReceive('targetFor')->once()->andReturn($this->target);
    $this->resolver->shouldReceive('assertMayReparent')->once()->with($this->user, $this->record);
    $this->linkRecords->shouldNotReceive('execute');

    $this->reparent->shouldReceive('execute')->once()
        ->with($this->record, ['parent_record_id' => (string) $this->target->getKey()]);

    $this->link->execute($this->user, $this->record, ($this->payload)('incoming'));
});

it('hangs the chosen record under this one for an outgoing hierarchy relation', function (): void {
    $type = ($this->relationshipType)(true);

    $this->resolver->shouldReceive('typeFor')->once()->andReturn($type);
    $this->resolver->shouldReceive('assertMayViewCounterpart')->once();
    $this->resolver->shouldReceive('targetFor')->once()->andReturn($this->target);
    $this->resolver->shouldReceive('assertMayReparent')->once()->with($this->user, $this->record);
    $this->linkRecords->shouldNotReceive('execute');

    $this->reparent->shouldReceive('execute')->once()
        ->with($this->target, ['parent_record_id' => (string) $this->record->getKey()]);

    $this->link->execute($this->user, $this->record, ($this->payload)('outgoing'));
});

it('refuses a hierarchy relation without the reparent ability and moves nothing', function (): void {
    $type = ($this->relationshipType)(true);

    $this->resolver->shouldReceive('typeFor')->once()->andReturn($type);
    $this->resolver->shouldReceive('assertMayViewCounterpart')->once();
    $this->resolver->shouldReceive('targetFor')->once()->andReturn($this->target);
    $this->resolver->shouldReceive('assertMayReparent')->once()->andThrow(new AuthorizationException);
    $this->reparent->shouldNotReceive('execute');
    $this->linkRecords->shouldNotReceive('execute');

    expect(fn () => $this->link->execute($this->user, $this->record, ($this->payload)('incoming')))
        ->toThrow(AuthorizationException::class);
});

it('refuses a relation whose counterpart the user may not view and writes nothing', function (): void {
    $type = ($this->relationshipType)(false);

    $this->resolver->shouldReceive('typeFor')->once()->andReturn($type);
    $this->resolver->shouldReceive('assertMayViewCounterpart')->once()->andThrow(new AuthorizationException);
    $this->resolver->shouldNotReceive('targetFor');
    $this->linkRecords->shouldNotReceive('execute');
    $this->reparent->shouldNotReceive('execute');

    expect(fn () => $this->link->execute($this->user, $this->record, ($this->payload)('outgoing')))
        ->toThrow(AuthorizationException::class);
});

it('writes nothing when the relationship type belongs to neither end of the record', function (): void {
    $this->resolver->shouldReceive('typeFor')->once()
        ->andThrow(ValidationException::withMessages(['relationship_type_id' => 'foreign']));
    $this->resolver->shouldNotReceive('targetFor');
    $this->linkRecords->shouldNotReceive('execute');
    $this->reparent->shouldNotReceive('execute');

    expect(fn () => $this->link->execute($this->user, $this->record, ($this->payload)('outgoing')))
        ->toThrow(ValidationException::class);
});
