<?php

declare(strict_types=1);

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportGroupRowData;
use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Models\FieldDefinition;
use App\Support\Reports\ReportResultAssembler;
use Illuminate\Support\Facades\Config;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Config::set('reports.k_anonymity_threshold', 5);
    Config::set('reports.max_categories', 5);

    $groupField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('group-field'),
        'key' => 'city',
    ]);

    /** @var callable(AggregationType, ?FieldDefinition):ReportDefinitionData */
    $this->definition = static fn (
        AggregationType $aggregation,
        ?FieldDefinition $series = null,
    ): ReportDefinitionData => new ReportDefinitionData(
        ModelStub::ulid('companies'),
        $aggregation,
        null,
        $groupField,
        null,
        $series,
        [],
        [],
        [],
    );

    /** @var callable(?string, string|int|null, int, array<string, mixed>):ReportGroupRowData */
    $this->row = static fn (
        ?string $group,
        string|int|null $value,
        int $recordCount,
        array $extra = [],
    ): ReportGroupRowData => new ReportGroupRowData(
        $group,
        $extra['series'] ?? null,
        $value === null ? null : (string) $value,
        $recordCount,
        $extra['valueSum'] ?? null,
        $extra['valueCount'] ?? null,
        $extra['discarded'] ?? 0,
    );

    /** @var callable(ReportResultData):list<array<string, mixed>> */
    $this->shapeOf = static fn (ReportResultData $result): array => array_map(
        static fn (ReportGroupRowData $row): array => [
            'group' => $row->groupValue,
            'series' => $row->seriesValue,
            'value' => $row->value,
            'records' => $row->recordCount,
            'other' => $row->isOtherGroup,
        ],
        $result->rows,
    );

    $this->assembler = new ReportResultAssembler;
});

it('folds every category below the threshold into the collector row that closes the result', function (): void {
    $rows = [
        ($this->row)('Berlin', 9, 9),
        ($this->row)('Hamburg', 7, 7),
        ($this->row)('Kiel', 2, 2),
        ($this->row)('Ulm', 3, 3),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '9', 'records' => 9, 'other' => false],
        ['group' => 'Hamburg', 'series' => null, 'value' => '7', 'records' => 7, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '5', 'records' => 5, 'other' => true],
    ]);
});

it('never publishes a row that carries fewer records than the threshold', function (): void {
    $rows = [
        ($this->row)('Berlin', 9, 9),
        ($this->row)('Kiel', 1, 1),
        ($this->row)('Ulm', 1, 1),
        ($this->row)('Bonn', 1, 1),
        ($this->row)('Trier', 1, 1),
        ($this->row)('Jena', 1, 1),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    $thin = array_values(array_filter(
        $result->rows,
        static fn (ReportGroupRowData $row): bool => $row->recordCount < 5,
    ));

    expect($result->rows)->not->toBeEmpty()
        ->and($thin)->toBe([]);
});

it('withholds the whole result when the collector alone stays below the threshold', function (): void {
    $rows = [
        ($this->row)('Kiel', 2, 2),
        ($this->row)('Ulm', 1, 1),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect($result->isSuppressed)->toBeTrue()
        ->and($result->rows)->toBe([])
        ->and($result->total)->toBeNull()
        ->and($result->recordCount)->toBe(0);
});

it('absorbs the weakest visible category when a lone thin category cannot reach the threshold alone', function (): void {
    $rows = [
        ($this->row)('Berlin', 20, 20),
        ($this->row)('Hamburg', 6, 6),
        ($this->row)('Kiel', 1, 1),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '20', 'records' => 20, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '7', 'records' => 7, 'other' => true],
    ]);
});

it('keeps a category that holds exactly the threshold visible instead of folding it', function (): void {
    $rows = [
        ($this->row)('Berlin', 9, 9),
        ($this->row)('Hamburg', 5, 5),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '9', 'records' => 9, 'other' => false],
        ['group' => 'Hamburg', 'series' => null, 'value' => '5', 'records' => 5, 'other' => false],
    ]);
});

it('reads the threshold from the configuration instead of a literal', function (): void {
    Config::set('reports.k_anonymity_threshold', 8);

    $rows = [
        ($this->row)('Berlin', 20, 20),
        ($this->row)('Hamburg', 7, 7),
        ($this->row)('Kiel', 6, 6),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '20', 'records' => 20, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '13', 'records' => 13, 'other' => true],
    ]);
});

it('reads the category cap from the configuration and folds the overflow', function (): void {
    Config::set('reports.max_categories', 2);

    $rows = [
        ($this->row)('Berlin', 20, 20),
        ($this->row)('Hamburg', 15, 15),
        ($this->row)('Kiel', 10, 10),
        ($this->row)('Ulm', 9, 9),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '20', 'records' => 20, 'other' => false],
        ['group' => 'Hamburg', 'series' => null, 'value' => '15', 'records' => 15, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '19', 'records' => 19, 'other' => true],
    ]);
});

it('gives a suppressed category no ranking slot even when its value is the largest', function (): void {
    Config::set('reports.max_categories', 2);

    $rows = [
        ($this->row)('Loud', 999, 2),
        ($this->row)('Berlin', 20, 20),
        ($this->row)('Hamburg', 15, 15),
        ($this->row)('Kiel', 10, 10),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    $groups = array_map(static fn (ReportGroupRowData $row): ?string => $row->groupValue, $result->rows);

    expect($groups)->toBe(['Berlin', 'Hamburg', null]);
});

it('keeps the sum over the published rows exact including the collector', function (): void {
    $rows = [
        ($this->row)('Berlin', '1250000.55', 9),
        ($this->row)('Hamburg', '99999.45', 7),
        ($this->row)('Kiel', '0.01', 3),
        ($this->row)('Ulm', '0.99', 3),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Sum),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '1250000.55', 'records' => 9, 'other' => false],
        ['group' => 'Hamburg', 'series' => null, 'value' => '99999.45', 'records' => 7, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '1', 'records' => 6, 'other' => true],
    ])
        ->and($result->total)->toBe('1350001')
        ->and($result->recordCount)->toBe(22);
});

it('averages the folded records over their true denominator instead of averaging the means', function (): void {
    $rows = [
        ($this->row)('Berlin', '10', 10, ['valueSum' => '100', 'valueCount' => 10]),
        ($this->row)('Kiel', '100', 2, ['valueSum' => '200', 'valueCount' => 2]),
        ($this->row)('Ulm', '1', 4, ['valueSum' => '4', 'valueCount' => 4]),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Avg),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '10', 'records' => 10, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '34', 'records' => 6, 'other' => true],
    ]);
});

it('keeps the collector denominator at the usable values when folded groups carry unusable ones', function (): void {
    $rows = [
        ($this->row)('Berlin', '10', 10, ['valueSum' => '100', 'valueCount' => 10]),
        ($this->row)('Kiel', '50', 3, ['valueSum' => '100', 'valueCount' => 2, 'discarded' => 1]),
        ($this->row)('Ulm', '25', 3, ['valueSum' => '50', 'valueCount' => 2, 'discarded' => 1]),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Avg),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    $collector = $result->rows[count($result->rows) - 1];

    expect($collector->isOtherGroup)->toBeTrue()
        ->and($collector->value)->toBe('37.5')
        ->and($collector->recordCount)->toBe(6)
        ->and($collector->discardedValueCount)->toBe(2);
});

it('asks the resolver for a distinct count over the folded rows instead of adding them up', function (): void {
    $rows = [
        ($this->row)('Berlin', '9', 9),
        ($this->row)('Kiel', '2', 3),
        ($this->row)('Ulm', '2', 3),
    ];

    $asked = [];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::DistinctCount),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
        null,
        function (array $groups) use (&$asked): array {
            $asked = $groups;

            return ['3'];
        },
    );

    $collector = $result->rows[count($result->rows) - 1];

    expect($asked)->toHaveCount(1)
        ->and($asked[0])->toHaveCount(2)
        ->and($collector->isOtherGroup)->toBeTrue()
        ->and($collector->value)->toBe('3');
});

it('folds a thin cell into a collector series of its own category rather than into the global collector', function (): void {
    $seriesField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('series-field'),
        'key' => 'status',
    ]);

    $categoryRows = [
        ($this->row)('Berlin', 20, 20),
        ($this->row)('Hamburg', 12, 12),
    ];

    $cellRows = [
        ($this->row)('Berlin', 18, 18, ['series' => 'open']),
        ($this->row)('Berlin', 2, 2, ['series' => 'won']),
        ($this->row)('Hamburg', 12, 12, ['series' => 'open']),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count, $seriesField),
        $categoryRows,
        $cellRows,
        '2026-09-23T00:00:00+00:00',
    );

    $collapsed = array_values(array_filter(
        $result->rows,
        static fn (ReportGroupRowData $row): bool => $row->isOtherSeries,
    ));

    expect($collapsed)->toHaveCount(1)
        ->and($collapsed[0]->groupValue)->toBe('Berlin')
        ->and($collapsed[0]->isOtherGroup)->toBeFalse()
        ->and($collapsed[0]->recordCount)->toBeGreaterThanOrEqual(5);
});

it('returns the neutral total of the aggregation for an empty stock', function (): void {
    $empty = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        [],
        [],
        '2026-09-23T00:00:00+00:00',
    );

    $emptySum = $this->assembler->assemble(
        ($this->definition)(AggregationType::Sum),
        [],
        [],
        '2026-09-23T00:00:00+00:00',
    );

    $emptyAvg = $this->assembler->assemble(
        ($this->definition)(AggregationType::Avg),
        [],
        [],
        '2026-09-23T00:00:00+00:00',
    );

    expect($empty->rows)->toBe([])
        ->and($empty->total)->toBe('0')
        ->and($empty->isSuppressed)->toBeFalse()
        ->and($emptySum->total)->toBe('0')
        ->and($emptyAvg->total)->toBeNull();
});

it('keeps the genuine empty group distinguishable from the collector row', function (): void {
    $rows = [
        ($this->row)(null, 9, 9),
        ($this->row)('Berlin', 7, 7),
        ($this->row)('Kiel', 2, 2),
        ($this->row)('Ulm', 3, 3),
    ];

    $result = $this->assembler->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    $unlabelled = array_values(array_filter(
        $result->rows,
        static fn (ReportGroupRowData $row): bool => $row->groupValue === null && !$row->isOtherGroup,
    ));

    expect($unlabelled)->toHaveCount(1)
        ->and($unlabelled[0]->recordCount)->toBe(9)
        ->and($result->rows[count($result->rows) - 1]->isOtherGroup)->toBeTrue();
});

it('folds a bucketed category exactly like any other category', function (): void {
    $definition = new ReportDefinitionData(
        ModelStub::ulid('companies'),
        AggregationType::Count,
        null,
        ModelStub::make(FieldDefinition::class, ['id' => ModelStub::ulid('date-field'), 'key' => 'closed_at']),
        GroupingBucket::Month,
        null,
        [],
        [],
        [],
    );

    $rows = [
        ($this->row)('2026-01-01T00:00:00+01:00', 9, 9),
        ($this->row)('2026-02-01T00:00:00+01:00', 2, 2),
        ($this->row)('2026-03-01T00:00:00+01:00', 4, 4),
    ];

    $result = $this->assembler->assemble($definition, $rows, $rows, '2026-09-23T00:00:00+00:00');

    expect(($this->shapeOf)($result))->toBe([
        ['group' => '2026-01-01T00:00:00+01:00', 'series' => null, 'value' => '9', 'records' => 9, 'other' => false],
        ['group' => null, 'series' => null, 'value' => '6', 'records' => 6, 'other' => true],
    ]);
});

it('honours an injected threshold over the configured one', function (): void {
    $rows = [
        ($this->row)('Berlin', 3, 3),
        ($this->row)('Kiel', 1, 1),
    ];

    $result = (new ReportResultAssembler(1))->assemble(
        ($this->definition)(AggregationType::Count),
        $rows,
        $rows,
        '2026-09-23T00:00:00+00:00',
    );

    expect(($this->shapeOf)($result))->toBe([
        ['group' => 'Berlin', 'series' => null, 'value' => '3', 'records' => 3, 'other' => false],
        ['group' => 'Kiel', 'series' => null, 'value' => '1', 'records' => 1, 'other' => false],
    ]);
});
