<?php

declare(strict_types=1);

use App\Actions\Engine\LinkRecordsAction;
use App\DTOs\Engine\RecordTreeNode;
use App\DTOs\Engine\RecordTreeResult;
use App\Enums\Engine\RelationCardinality;
use App\Exceptions\Engine\HierarchyCycleException;
use App\Models\CustomRecord;
use App\Support\Engine\HierarchyCycleGuard;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordLinkCardinalityGuard;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Timeline\RelationTimelineWriter;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->carrierId = ModelStub::ulid('carrier');
    $this->parentId = ModelStub::ulid('parent-record');
    $this->childId = ModelStub::ulid('child-record');

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "relationship_types"') ? [($this->relationshipRow)()] : [],
        static fn (): int => 1,
    );

    $this->treeQuery = Mockery::mock(RecordTreeQuery::class);

    /** @var callable(list<RecordTreeNode>, bool, bool):RecordTreeResult */
    $this->traversal = fn (array $nodes = [], bool $isTruncated = false, bool $isCycleDetected = false): RecordTreeResult => new RecordTreeResult($nodes, $isTruncated, $isCycleDetected);

    $this->node = fn (string $recordId): RecordTreeNode => new RecordTreeNode(recordId: $recordId, objectTypeId: ModelStub::ulid('companies'), depth: 1);

    $this->guard = fn (): HierarchyCycleGuard => new HierarchyCycleGuard($this->treeQuery);

    $this->relationshipRow = fn (bool $isHierarchy = true): array => [
        'id' => $this->carrierId,
        'tenant_id' => $this->tenantId,
        'from_object_type_id' => ModelStub::ulid('companies'),
        'to_object_type_id' => ModelStub::ulid('companies'),
        'cardinality' => RelationCardinality::OneToMany->value,
        'is_hierarchy' => $isHierarchy,
    ];

    /** @var callable(callable):array<string, mixed> */
    $this->refusal = function (callable $attempt): array {
        try {
            $attempt();
        } catch (ValidationException $exception) {
            return ['class' => $exception::class, 'errors' => $exception->errors()];
        }

        return [];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('serialises every change of one hierarchy behind a transaction scoped advisory lock before it reads the tree', function (): void {
    $this->treeQuery->shouldReceive('descendantsOf')
        ->once()
        ->with($this->tenantId, $this->carrierId, $this->childId)
        ->andReturnUsing(function (): RecordTreeResult {
            expect($this->connection->queries)->toHaveCount(1);

            return ($this->traversal)();
        });

    ($this->guard)()->assertAcyclic($this->tenantId, $this->carrierId, $this->parentId, $this->childId);

    expect($this->connection->queries[0]['sql'])->toBe('select pg_advisory_xact_lock(hashtext(?::text), hashtext(?::text))')
        ->and($this->connection->queries[0]['bindings'])->toBe([$this->tenantId, $this->carrierId]);
});

it('refuses a record as its own parent without walking the tree', function (): void {
    $this->treeQuery->shouldNotReceive('descendantsOf');

    expect(($this->refusal)(fn () => ($this->guard)()->assertAcyclic($this->tenantId, $this->carrierId, $this->childId, $this->childId)))
        ->toBe(['class' => HierarchyCycleException::class, 'errors' => ['to_record_id' => ['Ein Datensatz kann nicht sein eigener übergeordneter Datensatz sein.']]]);
});

it('refuses a parent that already hangs below the child', function (): void {
    $this->treeQuery->shouldReceive('descendantsOf')->once()->andReturn(($this->traversal)([
        ($this->node)(ModelStub::ulid('grandchild')),
        ($this->node)($this->parentId),
    ]));

    expect(($this->refusal)(fn () => ($this->guard)()->assertAcyclic($this->tenantId, $this->carrierId, $this->parentId, $this->childId)))
        ->toBe(['class' => HierarchyCycleException::class, 'errors' => ['to_record_id' => ['Der gewählte übergeordnete Datensatz liegt unterhalb dieses Datensatzes.']]]);
});

it('refuses the link when the descendant traversal was incomplete and names it in the log', function (bool $isTruncated, bool $isCycleDetected): void {
    Log::spy();

    $this->treeQuery->shouldReceive('descendantsOf')->once()->andReturn(($this->traversal)([], $isTruncated, $isCycleDetected));

    expect(($this->refusal)(fn () => ($this->guard)()->assertAcyclic($this->tenantId, $this->carrierId, $this->parentId, $this->childId))['class'] ?? null)
        ->toBe(HierarchyCycleException::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['traversal'] === 'descendants'
            && $context['record_id'] === $this->childId
            && $context['new_parent_record_id'] === $this->parentId
            && $context['is_truncated'] === $isTruncated
            && $context['is_cycle_detected'] === $isCycleDetected);
})->with([
    'truncated' => [true, false],
    'cycle detected' => [false, true],
]);

it('runs every hierarchy link through the cycle guard inside the write transaction', function (): void {
    FakePresenceVerifier::holding(['relationship_types' => [$this->carrierId]]);

    $parent = ModelStub::make(CustomRecord::class, ['id' => $this->parentId, 'tenant_id' => $this->tenantId]);
    $child = ModelStub::make(CustomRecord::class, ['id' => $this->childId, 'tenant_id' => $this->tenantId]);

    $endpoints = Mockery::mock(RecordEndpointResolver::class);
    $endpoints->shouldReceive('find')->with(ModelStub::ulid('companies'), $this->parentId)->andReturn($parent);
    $endpoints->shouldReceive('find')->with(ModelStub::ulid('companies'), $this->childId)->andReturn($child);

    $cardinality = Mockery::mock(RecordLinkCardinalityGuard::class);
    $cardinality->shouldReceive('assert');

    $guard = Mockery::mock(HierarchyCycleGuard::class);
    $guard->shouldReceive('assertAcyclic')
        ->once()
        ->with($this->tenantId, $this->carrierId, $this->parentId, $this->childId)
        ->andReturnUsing(function (): never {
            expect($this->connection->transactionLevel())->toBe(1);

            throw HierarchyCycleException::withMessages(['to_record_id' => 'cycle']);
        });

    $action = new LinkRecordsAction(
        Mockery::mock(RelationTimelineWriter::class),
        Mockery::mock(RollupOwnerStarter::class),
        $endpoints,
        $cardinality,
        $guard,
    );

    expect(fn () => $action->execute([
        'relationship_type_id' => $this->carrierId,
        'from_record_id' => $this->parentId,
        'to_record_id' => $this->childId,
    ]))->toThrow(HierarchyCycleException::class);

    expect($this->connection->writtenStatements)->toBe([]);
});

it('never asks the cycle guard for a relationship that is no hierarchy', function (): void {
    StaticQueryConnection::uninstall();
    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "relationship_types"') ? [($this->relationshipRow)(false)] : [],
        static fn (): int => 1,
    );
    FakePresenceVerifier::holding(['relationship_types' => [$this->carrierId]]);

    $parent = ModelStub::make(CustomRecord::class, ['id' => $this->parentId, 'tenant_id' => $this->tenantId]);
    $child = ModelStub::make(CustomRecord::class, ['id' => $this->childId, 'tenant_id' => $this->tenantId]);

    $endpoints = Mockery::mock(RecordEndpointResolver::class);
    $endpoints->shouldReceive('find')->andReturn($parent, $child);

    $cardinality = Mockery::mock(RecordLinkCardinalityGuard::class);
    $cardinality->shouldReceive('assert');

    $timeline = Mockery::mock(RelationTimelineWriter::class);
    $timeline->shouldReceive('record');

    $rollups = Mockery::mock(RollupOwnerStarter::class);
    $rollups->shouldReceive('startForLinkOwner');

    $guard = Mockery::mock(HierarchyCycleGuard::class);
    $guard->shouldNotReceive('assertAcyclic');

    (new LinkRecordsAction($timeline, $rollups, $endpoints, $cardinality, $guard))->execute([
        'relationship_type_id' => $this->carrierId,
        'from_record_id' => $this->parentId,
        'to_record_id' => $this->childId,
    ]);

    expect(collect($this->connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('insert into "record_links"');
});
