<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordSelectionResolver;
use App\Support\Export\CsvExportWriter;
use App\Support\Export\ExportColumnResolver;
use App\Support\Export\JsonExportWriter;
use App\Support\Export\RecordExportRunner;
use App\Support\Export\XlsxExportWriter;
use App\Support\Reports\ReportExecutionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    AccessContext::suspendRowAccess();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
    ]);

    $this->exporter = AccessContext::actAs(AccessContext::user($this->tenant, [], 'exporter'));

    $this->segment = ModelStub::make(Segment::class, [
        'id' => ModelStub::ulid('segment'),
        'tenant_id' => $this->tenantId,
        'filter_definition' => ['combinator' => 'and', 'conditions' => []],
    ]);

    $this->nameField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-name'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => 'name',
    ]);

    $this->selectionResolver = Mockery::mock(RecordSelectionResolver::class);
    $this->selectionResolver->shouldReceive('filterScope')->andReturn([
        'fields' => new Collection([$this->nameField]),
        'readable' => new Collection([$this->nameField]),
        'expressions' => [],
    ])->byDefault();
    $this->selectionResolver->shouldReceive('scopedQuery')->andReturnUsing(
        fn (): Builder => CustomRecord::query()->where('object_type_id', (string) $this->objectType->getKey()),
    )->byDefault();

    $this->filterCompiler = Mockery::mock(RecordFilterCompiler::class);
    $this->context = Mockery::mock(ReportExecutionContext::class);

    /** @var callable(?Segment):RecordExportRunner */
    $this->runnerSeeing = fn (?Segment $segment): RecordExportRunner => tap(
        Mockery::mock(RecordExportRunner::class, [
            Mockery::mock(ObjectTypeRegistry::class),
            Mockery::mock(ExportColumnResolver::class),
            Mockery::mock(CsvExportWriter::class),
            Mockery::mock(XlsxExportWriter::class),
            Mockery::mock(JsonExportWriter::class),
            $this->selectionResolver,
            $this->filterCompiler,
            $this->context,
        ])->makePartial()->shouldAllowMockingProtectedMethods(),
        static function (RecordExportRunner $runner) use ($segment): void {
            $runner->shouldReceive('segmentFor')->andReturn($segment);
        },
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('exports a whole type as a query bound to the tenant and the object type', function (): void {
    $query = ($this->runnerSeeing)(null)->scopedQuery($this->objectType, ['mode' => 'whole-type'], $this->tenantId, $this->exporter);
    $shape = QueryShape::of($query);

    expect($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->isScopedToTenant('custom_records', $this->tenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->objectType->getKey()))->toBeTrue()
        ->and($shape->blocksEveryRow())->toBeFalse();
});

it('exports nothing through a segment the exporter may not view', function (): void {
    GateSpy::allowing();
    $this->filterCompiler->shouldNotReceive('applyTree');

    $query = ($this->runnerSeeing)($this->segment)->scopedQuery(
        $this->objectType,
        ['mode' => 'segment', 'segmentId' => (string) $this->segment->getKey()],
        $this->tenantId,
        $this->exporter,
    );

    expect(QueryShape::of($query)->blocksEveryRow())->toBeTrue();
});

it('exports through a segment the exporter may view and applies its filter', function (): void {
    $spy = GateSpy::allowing('view');
    $this->filterCompiler->shouldReceive('applyTree')->once();

    $query = ($this->runnerSeeing)($this->segment)->scopedQuery(
        $this->objectType,
        ['mode' => 'segment', 'segmentId' => (string) $this->segment->getKey()],
        $this->tenantId,
        $this->exporter,
    );

    expect(QueryShape::of($query)->blocksEveryRow())->toBeFalse()
        ->and($spy->wasAskedFor('view'))->toBeTrue();
});

it('exports nothing when the segment of the scope no longer exists', function (): void {
    GateSpy::allowing('view');
    $this->filterCompiler->shouldNotReceive('applyTree');

    $query = ($this->runnerSeeing)(null)->scopedQuery(
        $this->objectType,
        ['mode' => 'segment', 'segmentId' => ModelStub::ulid('gone')],
        $this->tenantId,
        $this->exporter,
    );

    expect(QueryShape::of($query)->blocksEveryRow())->toBeTrue();
});

it('exports nothing for a view scope that filters on a column the exporter may not read', function (): void {
    $query = ($this->runnerSeeing)(null)->scopedQuery(
        $this->objectType,
        ['mode' => 'view', 'filterModel' => ['salary' => ['type' => 'equals', 'filter' => 1]]],
        $this->tenantId,
        $this->exporter,
    );

    expect(QueryShape::of($query)->blocksEveryRow())->toBeTrue();
});

it('exports a view scope whose filter columns the exporter may read', function (): void {
    $query = ($this->runnerSeeing)(null)->scopedQuery(
        $this->objectType,
        ['mode' => 'view', 'filterModel' => ['name' => ['type' => 'contains', 'filter' => 'Acme']]],
        $this->tenantId,
        $this->exporter,
    );

    $shape = QueryShape::of($query);

    expect($shape->blocksEveryRow())->toBeFalse()
        ->and($shape->isScopedToTenant('custom_records', $this->tenantId))->toBeTrue();
});

it('runs an export in the viewer context of the user who started it', function (): void {
    $this->context->shouldReceive('runAsViewer')
        ->once()
        ->with($this->tenantId, (string) $this->exporter->getKey(), Mockery::type(Closure::class))
        ->andReturnNull();
    $this->context->shouldNotReceive('runAsDefiner');

    ($this->runnerSeeing)(null)->run($this->tenantId, (string) $this->exporter->getKey(), ModelStub::ulid('export-job'));
});

it('loads the export job by its key inside that viewer context', function (): void {
    $this->context->shouldReceive('runAsViewer')->andReturnUsing(
        fn (string $tenantId, string $userId, Closure $callback): mixed => $callback($this->exporter),
    );

    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->runnerSeeing)(null)->run(
        $this->tenantId,
        (string) $this->exporter->getKey(),
        ModelStub::ulid('export-job'),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('export_jobs'))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('export-job')))->toBeTrue();
});
