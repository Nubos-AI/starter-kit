<?php

declare(strict_types=1);

use App\Actions\Engine\LinkRecordsAction;
use App\Actions\Engine\ReparentRecordAction;
use App\DTOs\Engine\RecordTreeNode;
use App\DTOs\Engine\RecordTreeResult;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\RecordHierarchyReader;
use App\Support\Engine\RecordTreeQuery;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->carrierId = ModelStub::ulid('carrier');
    $this->parentId = ModelStub::ulid('parent-record');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'hierarchy_relationship_type_id' => $this->carrierId,
    ]);

    /** @var callable(array<string, mixed>, ObjectType|null):CustomRecord */
    $this->record = fn (array $attributes = [], ?ObjectType $objectType = null): CustomRecord => ModelStub::make(
        CustomRecord::class,
        [
            'id' => ModelStub::ulid('company-record'),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => ($objectType ?? $this->objectType)->getKey(),
            ...$attributes,
        ],
        ['objectType' => $objectType ?? $this->objectType],
    );

    /** @var callable(list<RecordTreeNode>, bool, bool):RecordTreeResult */
    $this->traversal = fn (array $nodes = [], bool $isTruncated = false, bool $isCycleDetected = false): RecordTreeResult => new RecordTreeResult($nodes, $isTruncated, $isCycleDetected);

    $this->linkRecords = Mockery::mock(LinkRecordsAction::class);
    $this->treeQuery = Mockery::mock(RecordTreeQuery::class);
    $this->notifier = Mockery::mock(AncestorChainNotifier::class);
    $this->reader = Mockery::mock(RecordHierarchyReader::class);

    $this->reparent = new ReparentRecordAction(
        $this->linkRecords,
        $this->treeQuery,
        $this->notifier,
        $this->reader,
    );

    $this->linkRecords->shouldNotReceive('execute');
    $this->notifier->shouldNotReceive('notify');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a parent key that is no ulid before it looks anything up', function (): void {
    $this->reader->shouldNotReceive('parentCandidate');
    $this->reader->shouldNotReceive('parentIdsOf');

    expect(fn () => $this->reparent->execute(($this->record)(), ['parent_record_id' => 'not-a-ulid']))
        ->toThrow(ValidationException::class);
});

it('refuses a move in an object type that has no hierarchy carrier', function (): void {
    $flat = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('contacts'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'contacts',
        'hierarchy_relationship_type_id' => null,
    ]);

    $this->reader->shouldNotReceive('parentCandidate');

    try {
        $this->reparent->execute(($this->record)([], $flat), ['parent_record_id' => $this->parentId]);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['parent_record_id']);
    }
});

it('refuses to move a record that is already deleted', function (): void {
    $this->reader->shouldNotReceive('parentCandidate');

    try {
        $this->reparent->execute(
            ($this->record)(['deleted_at' => '2026-09-01 12:00:00']),
            ['parent_record_id' => $this->parentId],
        );
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['record']);
    }
});

it('refuses a parent the scoped lookup does not hand back', function (): void {
    $record = ($this->record)();

    $this->reader->shouldReceive('parentCandidate')->once()->with($record, $this->parentId)->andReturnNull();
    $this->reader->shouldNotReceive('parentIdsOf');

    try {
        $this->reparent->execute($record, ['parent_record_id' => $this->parentId]);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['parent_record_id']);
    }
});

it('leaves everything untouched when the record already hangs under that parent', function (): void {
    $record = ($this->record)();
    $parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($parent);
    $this->reader->shouldReceive('parentIdsOf')->once()
        ->with((string) $this->tenant->getKey(), $this->carrierId, (string) $record->getKey())
        ->andReturn([$this->parentId]);
    $this->treeQuery->shouldNotReceive('descendantsOf');
    $this->treeQuery->shouldNotReceive('ancestorsOf');

    $this->reparent->execute($record, ['parent_record_id' => $this->parentId]);
});

it('refuses a record as its own parent', function (): void {
    $record = ($this->record)();

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($record);
    $this->reader->shouldReceive('parentIdsOf')->once()->andReturn([]);
    $this->treeQuery->shouldNotReceive('descendantsOf');

    try {
        $this->reparent->execute($record, ['parent_record_id' => (string) $record->getKey()]);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['parent_record_id']);
    }
});

it('refuses a move under one of its own descendants', function (): void {
    $record = ($this->record)();
    $parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($parent);
    $this->reader->shouldReceive('parentIdsOf')->once()->andReturn([]);
    $this->treeQuery->shouldReceive('descendantsOf')->once()
        ->with((string) $this->tenant->getKey(), $this->carrierId, (string) $record->getKey())
        ->andReturn(($this->traversal)([
            new RecordTreeNode(recordId: ModelStub::ulid('child'), objectTypeId: (string) $this->objectType->getKey(), depth: 1),
            new RecordTreeNode(recordId: $this->parentId, objectTypeId: (string) $this->objectType->getKey(), depth: 2),
        ]));
    $this->treeQuery->shouldNotReceive('ancestorsOf');

    try {
        $this->reparent->execute($record, ['parent_record_id' => $this->parentId]);
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['parent_record_id']);
    }
});

it('refuses the move when the descendant traversal was truncated and names that traversal in the log', function (): void {
    Log::spy();

    $record = ($this->record)();
    $parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($parent);
    $this->reader->shouldReceive('parentIdsOf')->once()->andReturn([]);
    $this->treeQuery->shouldReceive('descendantsOf')->once()->andReturn(($this->traversal)([], true));

    expect(fn () => $this->reparent->execute($record, ['parent_record_id' => $this->parentId]))
        ->toThrow(ValidationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['traversal'] === 'descendants'
            && $context['is_truncated'] === true);
});

it('refuses the move when the descendant traversal ran into a cycle', function (): void {
    Log::spy();

    $record = ($this->record)();
    $parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($parent);
    $this->reader->shouldReceive('parentIdsOf')->once()->andReturn([]);
    $this->treeQuery->shouldReceive('descendantsOf')->once()->andReturn(($this->traversal)([], false, true));

    expect(fn () => $this->reparent->execute($record, ['parent_record_id' => $this->parentId]))
        ->toThrow(ValidationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['is_cycle_detected'] === true);
});

it('refuses the move when the chain above the record itself was truncated', function (): void {
    Log::spy();

    $record = ($this->record)();
    $parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($parent);
    $this->reader->shouldReceive('parentIdsOf')->once()->andReturn([]);
    $this->treeQuery->shouldReceive('descendantsOf')->once()->andReturn(($this->traversal)());
    $this->treeQuery->shouldReceive('ancestorsOf')->once()
        ->with((string) $this->tenant->getKey(), $this->carrierId, (string) $record->getKey())
        ->andReturn(($this->traversal)([], true));

    expect(fn () => $this->reparent->execute($record, ['parent_record_id' => $this->parentId]))
        ->toThrow(ValidationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['traversal'] === 'old_ancestors');
});

it('refuses the move when the chain above the new parent was truncated', function (): void {
    Log::spy();

    $record = ($this->record)();
    $parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->reader->shouldReceive('parentCandidate')->once()->andReturn($parent);
    $this->reader->shouldReceive('parentIdsOf')->once()->andReturn([]);
    $this->treeQuery->shouldReceive('descendantsOf')->once()->andReturn(($this->traversal)());
    $this->treeQuery->shouldReceive('ancestorsOf')->once()
        ->with((string) $this->tenant->getKey(), $this->carrierId, (string) $record->getKey())
        ->andReturn(($this->traversal)());
    $this->treeQuery->shouldReceive('ancestorsOf')->once()
        ->with((string) $this->tenant->getKey(), $this->carrierId, $this->parentId)
        ->andReturn(($this->traversal)([], true));

    expect(fn () => $this->reparent->execute($record, ['parent_record_id' => $this->parentId]))
        ->toThrow(ValidationException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['traversal'] === 'new_ancestors'
            && $context['new_parent_record_id'] === $this->parentId);
});
