<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDrillDownData;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Support\Engine\RecordSelectionResolver;
use App\Support\Reports\ReportGridScope;
use App\Support\Segments\SegmentResolver;
use App\Support\Segments\SystemSegmentDescriptor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    AccessContext::suspendRowAccess();

    $this->viewer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'bulk-viewer'));

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('selection-companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
    ]);

    $this->nameField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('selection-field-name'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => 'name',
        'field_type' => FieldType::TextShort->value,
        'is_filterable' => true,
        'is_encrypted' => false,
        'is_translatable' => false,
    ]);

    $this->segmentResolver = Mockery::mock(SegmentResolver::class);
    app()->instance(SegmentResolver::class, $this->segmentResolver);

    $this->reportGridScope = Mockery::mock(ReportGridScope::class);
    app()->instance(ReportGridScope::class, $this->reportGridScope);

    /** @var callable(array<string, mixed>):mixed */
    $this->select = fn (array $selection): mixed => app(RecordSelectionResolver::class)->scopedQuery(
        $this->objectType,
        $selection,
        new Collection([$this->nameField]),
        [],
        new Collection([$this->nameField]),
        $this->viewer,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('keeps the segment and the drill down of a selection through validation', function (): void {
    $validated = Validator::make([
        'selection' => [
            'mode' => 'all-matching',
            'segmentId' => 'system:mine',
            'drillDown' => ['report' => ModelStub::ulid('report'), 'group' => 'g1', 'series' => null],
        ],
    ], app(RecordSelectionResolver::class)->selectionRules(withSortModel: true))->validate();

    expect($validated['selection'])->toHaveKey('segmentId', 'system:mine')
        ->and($validated['selection']['drillDown'])->toMatchArray(['report' => ModelStub::ulid('report'), 'group' => 'g1']);
});

it('narrows an all matching selection to the segment the grid was showing', function (): void {
    $this->segmentResolver->shouldReceive('applyScope')
        ->once()
        ->withArgs(fn (mixed $query, Segment $segment, ObjectType $type, mixed $viewer, ?SystemSegmentDescriptor $descriptor): bool => $descriptor?->id === 'system:mine'
            && $type === $this->objectType
            && $viewer === $this->viewer);

    ($this->select)(['mode' => 'all-matching', 'segmentId' => 'system:mine', 'filterModel' => []]);
});

it('refuses an all matching selection through a segment the viewer may not see and never applies it', function (): void {
    GateSpy::allowing();
    $segmentId = ModelStub::ulid('foreign-segment');
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "segments"')
        ? [['id' => $segmentId, 'tenant_id' => $this->tenantId, 'object_type_id' => (string) $this->objectType->getKey(), 'filter_definition' => '{}']]
        : []);
    $this->segmentResolver->shouldNotReceive('applyScope');

    expect(fn (): mixed => ($this->select)(['mode' => 'all-matching', 'segmentId' => $segmentId]))
        ->toThrow(AuthorizationException::class);
});

it('refuses an all matching selection whose segment was deleted after it was frozen instead of widening it', function (): void {
    StaticQueryConnection::install(static fn (): array => []);
    $this->segmentResolver->shouldNotReceive('applyScope');

    expect(fn (): mixed => ($this->select)(['mode' => 'all-matching', 'segmentId' => ModelStub::ulid('deleted-segment')]))
        ->toThrow(ModelNotFoundException::class);
});

it('narrows an all matching selection to the report section it was drilled into', function (): void {
    GateSpy::allowing('view');
    $reportId = ModelStub::ulid('drill-report');
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "reports"')
        ? [['id' => $reportId, 'tenant_id' => $this->tenantId, 'object_type_id' => (string) $this->objectType->getKey()]]
        : []);
    $this->reportGridScope->shouldReceive('apply')
        ->once()
        ->withArgs(static fn (mixed $query, array $definition, ReportDrillDownData $drillDown): bool => $drillDown->reportId === $reportId
            && $drillDown->groupToken === 'north'
            && $drillDown->seriesToken === null);

    ($this->select)([
        'mode' => 'all-matching',
        'drillDown' => ['report' => $reportId, 'group' => 'north', 'series' => null],
    ]);
});

it('refuses a selection that combines a drill down with a segment', function (): void {
    $this->segmentResolver->shouldNotReceive('applyScope');
    $this->reportGridScope->shouldNotReceive('apply');

    expect(fn (): mixed => ($this->select)([
        'mode' => 'all-matching',
        'segmentId' => 'system:mine',
        'drillDown' => ['report' => ModelStub::ulid('drill-report'), 'group' => 'north'],
    ]))->toThrow(ValidationException::class);
});

it('leaves an explicit id selection untouched by segment and drill down', function (): void {
    $this->segmentResolver->shouldNotReceive('applyScope');
    $this->reportGridScope->shouldNotReceive('apply');

    $shape = QueryShape::of(($this->select)([
        'mode' => 'visible',
        'segmentId' => 'system:mine',
        'includedIds' => [ModelStub::ulid('picked')],
    ]));

    expect($shape->hasBinding(ModelStub::ulid('picked')))->toBeTrue();
});

it('refuses an all matching selection whose filter the viewer cannot resolve instead of widening it', function (): void {
    $errors = [];

    try {
        ($this->select)([
            'mode' => 'all-matching',
            'filterModel' => ['salary' => ['type' => 'equals', 'filter' => 1]],
        ]);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toHaveKey('selection.filterModel')
        ->and($errors['selection.filterModel'][0])->toContain('Filter');
});

it('refuses an all matching selection whose operator does not fit the field', function (): void {
    expect(fn (): mixed => ($this->select)([
        'mode' => 'all-matching',
        'filterModel' => ['name' => ['type' => 'greaterThan', 'filter' => 5]],
    ]))->toThrow(ValidationException::class);
});

it('applies a filter the viewer can resolve', function (): void {
    $shape = QueryShape::of(($this->select)([
        'mode' => 'all-matching',
        'filterModel' => ['name' => ['type' => 'contains', 'filter' => 'Acme']],
    ]));

    expect($shape->targets((new CustomRecord)->getTable()))->toBeTrue()
        ->and($shape->hasBinding('%Acme%'))->toBeTrue();
});

it('narrows through the signed in user when a caller hands over no viewer', function (): void {
    $this->segmentResolver->shouldReceive('applyScope')
        ->once()
        ->withArgs(fn (mixed $query, Segment $segment, ObjectType $type, mixed $viewer): bool => $viewer === $this->viewer);

    app(RecordSelectionResolver::class)->scopedQuery(
        $this->objectType,
        ['mode' => 'all-matching', 'segmentId' => 'system:mine'],
        new Collection([$this->nameField]),
    );
});

it('refuses to narrow to a segment when nobody is there to check it for', function (): void {
    Auth::forgetGuards();
    $this->segmentResolver->shouldNotReceive('applyScope');

    expect(fn (): mixed => app(RecordSelectionResolver::class)->scopedQuery(
        $this->objectType,
        ['mode' => 'all-matching', 'segmentId' => 'system:mine'],
        new Collection([$this->nameField]),
    ))->toThrow(AuthorizationException::class);
});
