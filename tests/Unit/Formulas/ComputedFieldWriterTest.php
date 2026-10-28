<?php

declare(strict_types=1);

use App\DTOs\Formulas\FormulaErrorValue;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaErrorCode;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ComputedFieldOrderResolver;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\ObjectTypeFieldLookup;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('writer-deals');
    $this->recordId = ModelStub::ulid('writer-deal');

    $this->objectBase = "(case when jsonb_typeof(custom_records.data) = 'object' then custom_records.data else '{}'::jsonb end)";

    /** @var callable(string, FieldType, ?array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, ?array $config = null): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("writer-field-{$key}"),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => $type,
        'is_encrypted' => false,
        'config' => $config,
    ]);

    /** @var callable(string, string, string):FieldDefinition */
    $this->formula = fn (string $key, string $formula, string $resultType): FieldDefinition => ($this->field)(
        $key,
        FieldType::Computed,
        ['formula' => $formula, 'result_type' => $resultType],
    );

    /** @var callable(?array<string, mixed>, ?string):CustomRecord */
    $this->record = fn (?array $data, ?string $tenantId = null): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $tenantId ?? $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'data' => $data,
    ]);

    $this->dealSources = [
        ($this->field)('amount', FieldType::Money),
        ($this->field)('probability', FieldType::Number),
        ($this->field)('close_date', FieldType::Date),
    ];

    $this->dealFormulas = [
        ($this->formula)('gross', '{amount} * 1.19', 'number'),
        ($this->formula)('tax', '{gross} - {amount}', 'number'),
        ($this->formula)('weighted', '{gross} * {probability} / 100', 'number'),
        ($this->formula)('is_large', '{gross} > 10000', 'boolean'),
        ($this->formula)('label', 'CONCAT("EUR "; {weighted})', 'text'),
        ($this->formula)('due', '{close_date}', 'date'),
    ];

    $this->dealData = ['title' => 'Deal', 'amount' => 10000, 'probability' => 50, 'close_date' => '2026-12-31'];

    /** @var callable(list<FieldDefinition>, list<FieldDefinition>):ComputedFieldWriter */
    $this->writer = function (array $sources, array $formulas): ComputedFieldWriter {
        app()->instance(
            ObjectTypeFieldLookup::class,
            StaticObjectTypeFieldLookup::carrying($this->objectTypeId, [...$sources, ...$formulas]),
        );

        $orderResolver = Mockery::mock(ComputedFieldOrderResolver::class);
        $orderResolver->shouldReceive('orderFor')->andReturn($formulas);
        app()->instance(ComputedFieldOrderResolver::class, $orderResolver);

        return app(ComputedFieldWriter::class);
    };

    /** @var callable(list<FieldDefinition>, list<FieldDefinition>, CustomRecord):?QueryShape */
    $this->attempt = function (array $sources, array $formulas, CustomRecord $record): ?QueryShape {
        $writer = ($this->writer)($sources, $formulas);

        return QueryShape::attemptedBy(fn (): mixed => $writer->materialize($record));
    };

    $this->returning = static fn (?string $data): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => $data === null ? [] : [['data' => $data]],
        static fn (): int => 1,
    );

    $this->statementCount = static fn (StaticQueryConnection $connection): int => count($connection->queries) + count($connection->writtenStatements);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

describe('ComputedFieldWriter writes every formula of a record in one statement', function (): void {
    test('a deal with six formula fields costs one statement instead of one write and one reload per formula', function (): void {
        $connection = ($this->returning)('{"title":"Deal"}');

        ($this->writer)($this->dealSources, $this->dealFormulas)->materialize(($this->record)($this->dealData));

        expect(($this->statementCount)($connection))->toBe(1)
            ->and($connection->writtenStatements)->toBe([]);
    });

    test('the single statement merges every formula value in evaluation order and writes only a changed row', function (): void {
        $shape = ($this->attempt)($this->dealSources, $this->dealFormulas, ($this->record)($this->dealData));

        $merged = "{$this->objectBase} || merged_values.data";

        expect($shape?->sql)->toBe(
            'with merged_values as (select jsonb_build_object('
            .'?::text, to_jsonb(?::numeric), '
            .'?::text, to_jsonb(?::numeric), '
            .'?::text, to_jsonb(?::numeric), '
            .'?::text, to_jsonb(?::boolean), '
            .'?::text, to_jsonb(?::text), '
            .'?::text, to_jsonb(?::text)'
            .') as data) '
            ."update custom_records set data = {$merged} from merged_values "
            .'where custom_records.id = ? and custom_records.tenant_id = ? '
            ."and custom_records.data is distinct from ({$merged}) "
            .'returning custom_records.data'
        )->and($shape?->bindings)->toBe([
            'gross', '11900',
            'tax', '1900',
            'weighted', '5950',
            'is_large', 'true',
            'label', 'EUR 5950',
            'due', '2026-12-31',
            $this->recordId, $this->tenantId,
        ]);
    });

    test('a formula built on another formula sees the value computed before it in the same pass', function (): void {
        $formulas = [
            ($this->formula)('gross', '{amount} * 2', 'number'),
            ($this->formula)('double_gross', '{gross} * 2', 'number'),
        ];

        $shape = ($this->attempt)($this->dealSources, $formulas, ($this->record)(['amount' => 5, 'gross' => 1]));

        expect($shape?->bindings)->toBe(['gross', '10', 'double_gross', '20', $this->recordId, $this->tenantId]);
    });

    test('the returned row becomes the model data so later steps read what the database holds', function (): void {
        $connection = ($this->returning)('{"title":"Deal","amount":5,"gross":10,"double_gross":20}');
        $record = ($this->record)(['title' => 'Deal', 'amount' => 5]);

        $written = ($this->writer)($this->dealSources, [
            ($this->formula)('gross', '{amount} * 2', 'number'),
            ($this->formula)('double_gross', '{gross} * 2', 'number'),
        ])->materialize($record);

        expect($written)->toBe(['gross', 'double_gross'])
            ->and($record->data)->toBe(['title' => 'Deal', 'amount' => 5, 'gross' => 10, 'double_gross' => 20])
            ->and($record->isDirty('data'))->toBeFalse()
            ->and(($this->statementCount)($connection))->toBe(1);
    });

    test('a row whose formula values are already in place keeps the model untouched', function (): void {
        ($this->returning)(null);
        $record = ($this->record)(['title' => 'Deal', 'amount' => 5, 'gross' => 10]);

        ($this->writer)($this->dealSources, [($this->formula)('gross', '{amount} * 2', 'number')])->materialize($record);

        expect($record->data)->toBe(['title' => 'Deal', 'amount' => 5, 'gross' => 10])
            ->and($record->isDirty('data'))->toBeFalse();
    });

    test('a record without formula fields sends no statement', function (): void {
        $connection = ($this->returning)('{}');

        $written = ($this->writer)($this->dealSources, [])->materialize(($this->record)($this->dealData));

        expect($written)->toBe([])
            ->and(($this->statementCount)($connection))->toBe(0);
    });

    test('more formulas than one json object call accepts are split into concatenated objects', function (): void {
        $formulas = array_map(
            fn (int $index): FieldDefinition => ($this->formula)("formula_{$index}", "{$index} + 1", 'number'),
            range(1, 51),
        );

        $shape = ($this->attempt)([], $formulas, ($this->record)([]));

        expect(substr_count((string) $shape?->sql, 'jsonb_build_object('))->toBe(2)
            ->and((string) $shape?->sql)->toContain('to_jsonb(?::numeric)) || jsonb_build_object(?::text, to_jsonb(?::numeric)')
            ->and(substr_count((string) $shape?->sql, '?::text, to_jsonb(?::numeric)'))->toBe(51);
    });
});

describe('ComputedFieldWriter keeps the json type of every result', function (): void {
    test('the write form follows the configured result type', function (
        string $formula,
        string $resultType,
        array $data,
        string $expectedExpression,
        string $expectedBinding,
    ): void {
        $shape = ($this->attempt)(
            [($this->field)('netto', FieldType::Number), ($this->field)('start_date', FieldType::Date)],
            [($this->formula)('result', $formula, $resultType)],
            ($this->record)($data),
        );

        expect($shape?->sql)->toContain("jsonb_build_object(?::text, {$expectedExpression})")
            ->and($shape?->bindings)->toBe(['result', $expectedBinding, $this->recordId, $this->tenantId]);
    })->with([
        'a true boolean result' => ['{netto} > 100', 'boolean', ['netto' => 150], 'to_jsonb(?::boolean)', 'true'],
        'a false boolean result' => ['{netto} > 100', 'boolean', ['netto' => 50], 'to_jsonb(?::boolean)', 'false'],
        'a text result' => ['CONCAT("EUR "; {netto})', 'text', ['netto' => 100], 'to_jsonb(?::text)', 'EUR 100'],
        'a date result' => ['{start_date}', 'date', ['start_date' => '2026-01-31'], 'to_jsonb(?::text)', '2026-01-31'],
    ]);

    test('a large decimal reaches the write without losing a digit', function (
        string $formula,
        string $amount,
        string $expected,
    ): void {
        $shape = ($this->attempt)(
            [($this->field)('amount', FieldType::Money)],
            [($this->formula)('exact', $formula, 'number')],
            ($this->record)(['amount' => $amount]),
        );

        expect($shape?->sql)->toContain('jsonb_build_object(?::text, to_jsonb(?::numeric))')
            ->and(bccomp((string) $shape?->bindings[1], $expected, 10))->toBe(0);
    })->with([
        'thirteen significant digits' => ['{amount} + 0', '12345678901.23', '12345678901.23'],
        'a quotient that fills the configured scale' => ['{amount} / 3', '1000000', '333333.3333333333'],
        'an amount at the edge of double precision' => ['{amount} + 0', '99999999999999.9', '99999999999999.9'],
        'sixteen significant digits' => ['{amount} + 0', '123456789.1234567', '123456789.1234567'],
    ]);

    test('an error value is written as a json object that reads back with its cause', function (): void {
        $shape = ($this->attempt)(
            [($this->field)('empty_number', FieldType::Number)],
            [($this->formula)('result', '{empty_number} + 1', 'number')],
            ($this->record)([]),
        );

        expect($shape?->sql)->toContain('jsonb_build_object(?::text, ?::jsonb)');

        $error = FormulaErrorValue::fromArray(json_decode((string) $shape?->bindings[1], true, 512, JSON_THROW_ON_ERROR));

        expect($error->code)->toBe(FormulaErrorCode::NotANumber)
            ->and($error->fieldKey)->toBe('empty_number');
    });

    test('a formula built on a failed formula reads the stored error object, exactly as after a reload', function (): void {
        $shape = ($this->attempt)(
            [($this->field)('empty_number', FieldType::Number)],
            [
                ($this->formula)('broken', '{empty_number} + 1', 'number'),
                ($this->formula)('follow_up', '{broken} + 1', 'number'),
            ],
            ($this->record)([]),
        );

        expect($shape?->sql)->toContain('jsonb_build_object(?::text, ?::jsonb, ?::text, ?::jsonb)')
            ->and($shape?->bindings[2])->toBe('follow_up');
    });

    test('a record whose data column is not an object gets one created by the write itself', function (): void {
        $shape = ($this->attempt)([], [($this->formula)('result', '2 + 3', 'number')], ($this->record)(null));

        expect($shape?->sql)->toContain("update custom_records set data = {$this->objectBase} || merged_values.data");
    });
});

describe('ComputedFieldWriter never leaves the tenant of the record', function (): void {
    test('the write addresses the single row it was handed within its tenant', function (): void {
        $shape = ($this->attempt)($this->dealSources, [($this->formula)('gross', '{amount} * 2', 'number')], ($this->record)(['amount' => 5]));

        expect($shape?->sql)->toContain('where custom_records.id = ? and custom_records.tenant_id = ?')
            ->and(array_slice((array) $shape?->bindings, -2))->toBe([$this->recordId, $this->tenantId]);
    });

    test('a record handed with a foreign tenant carries that tenant into the write and never the acting one', function (): void {
        $foreignTenantId = ModelStub::ulid('writer-foreign-tenant');

        $shape = ($this->attempt)(
            $this->dealSources,
            [($this->formula)('gross', '{amount} * 2', 'number')],
            ($this->record)(['amount' => 5], $foreignTenantId),
        );

        expect($shape?->hasBinding($foreignTenantId))->toBeTrue()
            ->and($shape?->hasBinding($this->tenantId))->toBeFalse();
    });
});
