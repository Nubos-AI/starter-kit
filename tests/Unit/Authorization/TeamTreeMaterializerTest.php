<?php

declare(strict_types=1);

use App\Exceptions\Authorization\TeamHierarchyException;
use App\Models\Team;
use App\Support\Authorization\TeamTreeMaterializer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->nodeCap = TeamTreeMaterializer::$maxTreeNodes;
    $this->materializer = app(TeamTreeMaterializer::class);

    /** @var callable(string, ?string, bool):Team */
    $this->node = fn (string $seed, ?string $parentId = null, bool $trashed = false): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'parent_team_id' => $parentId,
        'ancestor_team_ids' => [],
        'descendant_team_ids' => [],
        'deleted_at' => $trashed ? '2026-01-01 00:00:00' : null,
    ]);

    /** @var callable(Team ...):EloquentCollection<int, Team> */
    $this->tree = static fn (Team ...$nodes): EloquentCollection => new EloquentCollection($nodes);

    $this->rootId = ModelStub::ulid('root');
    $this->branchId = ModelStub::ulid('branch');
    $this->leafId = ModelStub::ulid('leaf');
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    TeamTreeMaterializer::$maxTreeNodes = $this->nodeCap;
    AccessContext::forgetTenant();
});

it('walks the ancestors from the direct parent up to the root', function (): void {
    $rows = ($this->tree)(
        ($this->node)('root'),
        ($this->node)('branch', $this->rootId),
        ($this->node)('leaf', $this->branchId),
    );

    $derived = $this->materializer->derivedArraysFor($rows, []);

    expect($derived[$this->leafId]['ancestor_team_ids'])->toBe([$this->branchId, $this->rootId])
        ->and($derived[$this->branchId]['ancestor_team_ids'])->toBe([$this->rootId])
        ->and($derived[$this->rootId]['ancestor_team_ids'])->toBe([]);
});

it('collects every descendant of a node and not only its children', function (): void {
    $rows = ($this->tree)(
        ($this->node)('root'),
        ($this->node)('branch', $this->rootId),
        ($this->node)('leaf', $this->branchId),
    );

    $derived = $this->materializer->derivedArraysFor($rows, []);

    $expected = [$this->branchId, $this->leafId];
    sort($expected);

    expect($derived[$this->rootId]['descendant_team_ids'])->toBe($expected)
        ->and($derived[$this->branchId]['descendant_team_ids'])->toBe([$this->leafId])
        ->and($derived[$this->leafId]['descendant_team_ids'])->toBe([]);
});

it('drops a trashed node and the whole subtree below it out of the derived arrays', function (): void {
    $rows = ($this->tree)(
        ($this->node)('root'),
        ($this->node)('branch', $this->rootId, true),
        ($this->node)('leaf', $this->branchId),
    );

    $derived = $this->materializer->derivedArraysFor($rows, []);

    expect($derived[$this->branchId]['ancestor_team_ids'])->toBe([])
        ->and($derived[$this->branchId]['descendant_team_ids'])->toBe([])
        ->and($derived[$this->leafId]['ancestor_team_ids'])->toBe([])
        ->and($derived[$this->rootId]['descendant_team_ids'])->toBe([]);
});

it('computes against the new parent an override announces without touching the row', function (): void {
    $branch = ($this->node)('branch', $this->rootId);
    $rows = ($this->tree)(($this->node)('root'), $branch, ($this->node)('leaf', $this->branchId));

    $derived = $this->materializer->derivedArraysFor($rows, [$this->branchId => null]);

    expect($derived[$this->branchId]['ancestor_team_ids'])->toBe([])
        ->and($derived[$this->leafId]['ancestor_team_ids'])->toBe([$this->branchId])
        ->and($derived[$this->rootId]['descendant_team_ids'])->toBe([])
        ->and($branch->parent_team_id)->toBe($this->rootId);
});

it('refuses to derive anything from a parent chain that closes on itself', function (): void {
    $rows = ($this->tree)(
        ($this->node)('root', $this->branchId),
        ($this->node)('branch', $this->rootId),
    );

    expect(fn () => $this->materializer->derivedArraysFor($rows, []))
        ->toThrow(TeamHierarchyException::class);
});

it('refuses an edge that would hang a team below one of its own descendants', function (): void {
    $parents = [
        $this->rootId => null,
        $this->branchId => $this->rootId,
        $this->leafId => $this->branchId,
    ];

    expect(fn () => $this->materializer->assertEdgeClosesNoCycle($this->rootId, $this->leafId, $parents))
        ->toThrow(TeamHierarchyException::class);
});

it('accepts an edge that keeps the tree acyclic', function (): void {
    $parents = [
        $this->rootId => null,
        $this->branchId => $this->rootId,
        $this->leafId => null,
    ];

    $this->materializer->assertEdgeClosesNoCycle($this->leafId, $this->branchId, $parents);
})->throwsNoExceptions();

it('refuses an edge whose parent chain already runs in a circle', function (): void {
    $parents = [
        $this->branchId => $this->leafId,
        $this->leafId => $this->branchId,
    ];

    expect(fn () => $this->materializer->assertEdgeClosesNoCycle($this->rootId, $this->branchId, $parents))
        ->toThrow(TeamHierarchyException::class);
});

it('refuses a tenant tree beyond the node cap', function (): void {
    TeamTreeMaterializer::$maxTreeNodes = 2;

    $rows = ($this->tree)(($this->node)('root'), ($this->node)('branch'), ($this->node)('leaf'));

    expect(fn () => $this->materializer->assertNodeCap($rows))
        ->toThrow(TeamHierarchyException::class);
});

it('lets a tenant tree exactly at the node cap through', function (): void {
    TeamTreeMaterializer::$maxTreeNodes = 2;

    $this->materializer->assertNodeCap(($this->tree)(($this->node)('root'), ($this->node)('branch')));
})->throwsNoExceptions();

it('locks the whole tenant tree in a stable order before touching it', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->materializer->lockTenant(($this->node)('root')));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->sql)->toContain('order by "id" asc')
        ->and($shape->sql)->toContain('for update')
        ->and(strpos($shape->sql, 'order by "id" asc'))->toBeLessThan(strpos($shape->sql, 'for update'))
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('refuses a parent that belongs to another tenant', function (): void {
    $foreign = ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('foreign-parent'),
        'tenant_id' => ModelStub::ulid('other-tenant'),
        'name' => 'foreign',
        'deleted_at' => null,
    ]);

    expect(fn () => $this->materializer->assertParentIsAssignable(($this->node)('root'), $foreign))
        ->toThrow(TeamHierarchyException::class);
});

it('refuses a trashed parent', function (): void {
    expect(fn () => $this->materializer->assertParentIsAssignable(($this->node)('leaf'), ($this->node)('branch', null, true)))
        ->toThrow(TeamHierarchyException::class);
});

it('writes not a single row when every stored array already matches the derived one', function (): void {
    $connection = StaticQueryConnection::install(
        fn (): array => [
            ['id' => $this->rootId, 'tenant_id' => (string) $this->tenant->getKey(), 'name' => 'root', 'parent_team_id' => null, 'ancestor_team_ids' => '[]', 'descendant_team_ids' => json_encode([$this->branchId]), 'deleted_at' => null],
            ['id' => $this->branchId, 'tenant_id' => (string) $this->tenant->getKey(), 'name' => 'branch', 'parent_team_id' => $this->rootId, 'ancestor_team_ids' => json_encode([$this->rootId]), 'descendant_team_ids' => '[]', 'deleted_at' => null],
        ],
        static fn (): int => 1,
    );

    $this->materializer->rematerialize(($this->node)('root'));

    expect($connection->writtenStatements)->toBe([]);
});

it('touches only the row whose derived arrays have gone stale', function (): void {
    $connection = StaticQueryConnection::install(
        fn (): array => [
            ['id' => $this->rootId, 'tenant_id' => (string) $this->tenant->getKey(), 'name' => 'root', 'parent_team_id' => null, 'ancestor_team_ids' => '[]', 'descendant_team_ids' => '[]', 'deleted_at' => null],
            ['id' => $this->branchId, 'tenant_id' => (string) $this->tenant->getKey(), 'name' => 'branch', 'parent_team_id' => $this->rootId, 'ancestor_team_ids' => json_encode([$this->rootId]), 'descendant_team_ids' => '[]', 'deleted_at' => null],
        ],
        static fn (): int => 1,
    );

    $this->materializer->rematerialize(($this->node)('root'));

    expect($connection->writtenStatements)->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toStartWith('update "teams"')
        ->and($connection->writtenStatements[0]['bindings'])->toContain(json_encode([$this->branchId]))
        ->and($connection->writtenStatements[0]['bindings'])->toContain($this->rootId);
});
