<?php

declare(strict_types=1);

use App\Support\Engine\RecordTreeQuery;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Config::set('engine.hierarchy.max_traversal_depth', 3);
    Config::set('engine.hierarchy.max_result_rows', 5);

    $this->tenantId = ModelStub::ulid('tenant');
    $this->carrierId = ModelStub::ulid('carrier');
    $this->anchorId = ModelStub::ulid('anchor');

    /** @var callable(string, int, bool):stdClass */
    $this->row = function (string $seed, int $depth, bool $isCycle = false): stdClass {
        $row = new stdClass;
        $row->record_id = ModelStub::ulid($seed);
        $row->object_type_id = ModelStub::ulid('companies');
        $row->depth = $depth;
        $row->is_cycle = $isCycle;

        return $row;
    };

    /** @var callable(list<stdClass>):stdClass */
    $this->answering = function (array $rows): stdClass {
        $capture = new stdClass;
        $capture->sql = '';
        $capture->bindings = [];

        DB::shouldReceive('select')
            ->once()
            ->andReturnUsing(function (string $sql, array $bindings) use ($rows, $capture): array {
                $capture->sql = $sql;
                $capture->bindings = $bindings;

                return $rows;
            });

        return $capture;
    };

    $this->tree = new RecordTreeQuery;
});

it('walks an ancestor traversal from the child end of the carrier edge upwards', function (): void {
    $capture = ($this->answering)([]);

    $this->tree->ancestorsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($capture->sql)->toContain('join record_links rl on rl.to_record_id = walked.record_id')
        ->and($capture->sql)->toContain('join custom_records cr on cr.id = rl.from_record_id')
        ->and($capture->sql)->toContain('with recursive record_tree as');
});

it('walks a descendant traversal from the parent end of the same edge downwards', function (): void {
    $capture = ($this->answering)([]);

    $this->tree->descendantsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($capture->sql)->toContain('join record_links rl on rl.from_record_id = walked.record_id')
        ->and($capture->sql)->toContain('join custom_records cr on cr.id = rl.to_record_id');
});

it('pins the tenant on the anchor, on every edge and on every walked record', function (): void {
    $capture = ($this->answering)([]);

    $this->tree->ancestorsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($capture->sql)->toContain('anchor.tenant_id = ?')
        ->and($capture->sql)->toContain('rl.tenant_id = ?')
        ->and($capture->sql)->toContain('cr.tenant_id = ?')
        ->and($capture->bindings)->toBe([
            $this->anchorId,
            $this->tenantId,
            $this->tenantId,
            $this->carrierId,
            $this->tenantId,
            4,
            5,
        ]);
});

it('keeps soft-deleted records and the anchor itself out of every traversal', function (): void {
    $capture = ($this->answering)([]);

    $this->tree->descendantsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($capture->sql)->toContain('anchor.deleted_at is null')
        ->and($capture->sql)->toContain('cr.deleted_at is null')
        ->and($capture->sql)->toContain('where depth > 0');
});

it('binds the carrier so edges of another relationship type are never walked', function (): void {
    $capture = ($this->answering)([]);

    $this->tree->ancestorsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($capture->sql)->toContain('rl.relationship_type_id = ?')
        ->and($capture->bindings[3])->toBe($this->carrierId);
});

it('drops the nodes beyond the traversal depth ceiling and names the depth cause in the log', function (): void {
    Log::spy();

    ($this->answering)([
        ($this->row)('level-one', 1),
        ($this->row)('level-two', 2),
        ($this->row)('level-three', 3),
        ($this->row)('level-four', 4),
    ]);

    $result = $this->tree->ancestorsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($result->nodes)->toHaveCount(3)
        ->and($result->isTruncated)->toBeTrue()
        ->and(array_map(fn ($node): int => $node->depth, $result->nodes))->toBe([1, 2, 3]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['is_depth_ceiling_reached'] === true
            && $context['is_row_ceiling_reached'] === false
            && $context['record_id'] === $this->anchorId);
});

it('keeps a chain exactly as long as the ceiling and warns about nothing', function (): void {
    Log::spy();

    ($this->answering)([
        ($this->row)('level-one', 1),
        ($this->row)('level-two', 2),
        ($this->row)('level-three', 3),
    ]);

    $result = $this->tree->ancestorsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($result->nodes)->toHaveCount(3)
        ->and($result->isTruncated)->toBeFalse();

    Log::shouldNotHaveReceived('warning');
});

it('carries the cycle flag the recursive statement reported into the result and the node', function (): void {
    ($this->answering)([
        ($this->row)('level-one', 1),
        ($this->row)('level-one', 2, true),
    ]);

    $result = $this->tree->descendantsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($result->isCycleDetected)->toBeTrue()
        ->and($result->nodes[1]->isCycleDetected)->toBeTrue()
        ->and($result->nodes[0]->isCycleDetected)->toBeFalse();
});

it('flags the row ceiling when the statement returns as many rows as it may', function (): void {
    Log::spy();

    ($this->answering)([
        ($this->row)('one', 1),
        ($this->row)('two', 1),
        ($this->row)('three', 1),
        ($this->row)('four', 1),
        ($this->row)('five', 1),
    ]);

    $result = $this->tree->descendantsOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($result->nodes)->toHaveCount(5)
        ->and($result->isTruncated)->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['is_row_ceiling_reached'] === true
            && $context['is_depth_ceiling_reached'] === false
            && $context['row_count'] === 5);
});

it('reads the direct children through the parent end of the edge and bounds them by the row ceiling', function (): void {
    $capture = ($this->answering)([]);

    $this->tree->childrenOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect($capture->sql)->toContain('where rl.from_record_id = ?')
        ->and($capture->sql)->toContain('join custom_records cr on cr.id = rl.to_record_id')
        ->and($capture->sql)->toContain('cr.deleted_at is null')
        ->and($capture->bindings)->toBe([
            $this->anchorId,
            $this->tenantId,
            $this->carrierId,
            $this->tenantId,
            5,
        ]);
});

it('reports every direct child at depth one and flags the shortened list at the row ceiling', function (): void {
    Log::spy();

    ($this->answering)([
        ($this->row)('one', 0),
        ($this->row)('two', 0),
        ($this->row)('three', 0),
        ($this->row)('four', 0),
        ($this->row)('five', 0),
    ]);

    $result = $this->tree->childrenOf($this->tenantId, $this->carrierId, $this->anchorId);

    expect(array_map(fn ($node): int => $node->depth, $result->nodes))->toBe([1, 1, 1, 1, 1])
        ->and($result->isTruncated)->toBeTrue()
        ->and($result->isCycleDetected)->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $context['is_row_ceiling_reached'] === true);
});
