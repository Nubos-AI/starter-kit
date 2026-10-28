<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\CustomFields\FilterOperator;
use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Engine\IndexCreationFailedException;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\IndexRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->registry = app(IndexRegistry::class);

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('index-field-'.$key),
            'object_type_id' => ModelStub::ulid('index-object-type'),
            'key' => $key,
            'field_type' => $type,
            'is_encrypted' => false,
            'is_searchable' => false,
            'is_sortable' => false,
            'is_filterable' => false,
            'is_translatable' => false,
            ...$overrides,
        ],
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
});

it('qualifies a hot field index by tenant and object type and hides soft deleted rows', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->registry->ensureHotFieldIndex(
        ($this->field)('umsatz', FieldType::Money, ['is_sortable' => true]),
    ));

    $objectTypeId = ModelStub::ulid('index-object-type');

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('(tenant_id, object_type_id, ')
        ->and($shape->sql)->toContain("WHERE object_type_id = '{$objectTypeId}' AND deleted_at IS NULL")
        ->and($shape->sql)->toContain("CASE WHEN data->>'umsatz' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (data->>'umsatz')::numeric END");
});

it('indexes a text field through the plain json text path without a numeric cast', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->registry->ensureHotFieldIndex(
        ($this->field)('titel', FieldType::TextShort, ['is_filterable' => true]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain("(data->>'titel')")
        ->and($shape->sql)->not->toContain('numeric');
});

it('gives two object types sharing a field key their own index name', function (): void {
    $here = ($this->field)('preis', FieldType::Money);
    $there = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('index-field-preis-other'),
        'object_type_id' => ModelStub::ulid('other-index-object-type'),
        'key' => 'preis',
        'field_type' => FieldType::TextShort,
    ]);

    expect($this->registry->indexNameFor($here))->not->toBe($this->registry->indexNameFor($there))
        ->and($this->registry->indexNameFor($here))->toStartWith('idx_custom_records_')
        ->and(strlen($this->registry->indexNameFor($here)))->toBeLessThan(64);
});

it('refuses to index an encrypted field and never sends any ddl', function (): void {
    $encrypted = ($this->field)('geheim', FieldType::TextShort, ['is_encrypted' => true, 'is_sortable' => true]);

    expect(fn (): mixed => $this->registry->ensureHotFieldIndex($encrypted))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): mixed => $this->registry->ensureLocaleHotFieldIndex($encrypted, 'de'))
        ->toThrow(InvalidArgumentException::class)
        ->and(QueryShape::attemptedBy(function () use ($encrypted): void {
            try {
                $this->registry->ensureHotFieldIndex($encrypted);
            } catch (InvalidArgumentException) {
                return;
            }
        }))->toBeNull();
});

it('refuses an unsafe field key before it can reach the ddl', function (string $key): void {
    $field = ($this->field)('valid_key', FieldType::TextShort);
    $field->key = $key;

    expect(fn (): mixed => $this->registry->ensureHotFieldIndex($field))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'sql payload' => 'evil"; DROP TABLE custom_records; --',
    'uppercase' => 'Titel',
    'leading digit' => '1titel',
]);

it('refuses an object type id that is not a ulid', function (): void {
    $field = ($this->field)('titel', FieldType::TextShort);
    $field->object_type_id = "x' OR 1=1 --";

    expect(fn (): mixed => $this->registry->ensureHotFieldIndex($field))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses a locale that is not a plain language tag', function (): void {
    expect(fn (): string => $this->registry->localeSortExpression('titel', "de'; DROP TABLE custom_records; --"))
        ->toThrow(InvalidArgumentException::class);
});

it('falls back to a second locale in the sort expression only when it differs', function (): void {
    expect($this->registry->localeSortExpression('titel', 'de', 'en'))
        ->toBe("COALESCE(data->'titel'->>'de', data->'titel'->>'en')")
        ->and($this->registry->localeSortExpression('titel', 'en', 'en'))
        ->toBe("(data->'titel'->>'en')");
});

it('names a locale index after the locale and its fallback', function (): void {
    $field = ($this->field)('titel', FieldType::TextShort, ['is_translatable' => true, 'is_sortable' => true]);
    $base = $this->registry->indexNameFor($field);

    expect($this->registry->indexNameForLocale($field, 'de', 'en'))->toBe("{$base}_de_en")
        ->and($this->registry->indexNameForLocale($field, 'en', 'en'))->toBe("{$base}_en");
});

it('limits locale indexes to their own object type while retaining fallback and stable ordering', function (): void {
    $field = ($this->field)('titel', FieldType::TextShort, ['is_translatable' => true, 'is_sortable' => true]);
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->registry->ensureLocaleHotFieldIndex($field, 'de', 'en'));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain("(tenant_id, object_type_id, COALESCE(data->'titel'->>'de', data->'titel'->>'en'), id)")
        ->and($shape->sql)->toContain("WHERE object_type_id = '{$field->object_type_id}' AND deleted_at IS NULL");
});

it('builds no trigram index of its own for a searchable text field, the shared search text index serves it', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->registry->ensureSortAndFilterIndexes(
        ($this->field)('firmenname', FieldType::TextShort, ['is_searchable' => true]),
    ));

    expect($shape)->toBeNull();
});

it('drops every index an object type still holds on the records table', function (): void {
    $objectTypeId = ModelStub::ulid('index-object-type');
    $prefix = 'idx_custom_records_'.substr(md5($objectTypeId), 0, 12).'_';

    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, '"pg_indexes"')
            ? [['indexname' => "{$prefix}titel"], ['indexname' => "{$prefix}titel_trgm"], ['indexname' => "{$prefix}name_de_en"]]
            : [],
        static fn (): int => 0,
    );

    $this->registry->dropObjectTypeIndexes($objectTypeId);

    expect(array_column($connection->writtenStatements, 'sql'))->toBe([
        "DROP INDEX CONCURRENTLY IF EXISTS {$prefix}titel",
        "DROP INDEX CONCURRENTLY IF EXISTS {$prefix}titel_trgm",
        "DROP INDEX CONCURRENTLY IF EXISTS {$prefix}name_de_en",
    ])
        ->and($connection->queries[0]['sql'])->toContain('from "pg_indexes"')
        ->and($connection->queries[0]['sql'])->toContain('starts_with(indexname, ?)')
        ->and($connection->queries[0]['bindings'])->toBe(['custom_records', $prefix]);
});

it('drops no index of another object type and no name outside the index naming scheme', function (): void {
    $objectTypeId = ModelStub::ulid('index-object-type');
    $prefix = 'idx_custom_records_'.substr(md5($objectTypeId), 0, 12).'_';
    $foreignPrefix = 'idx_custom_records_'.substr(md5(ModelStub::ulid('other-index-object-type')), 0, 12).'_';

    $connection = StaticQueryConnection::install(
        static fn (): array => [
            ['indexname' => "{$foreignPrefix}titel"],
            ['indexname' => "{$prefix}titel; DROP TABLE custom_records"],
        ],
        static fn (): int => 0,
    );

    $this->registry->dropObjectTypeIndexes($objectTypeId);

    expect($connection->writtenStatements)->toBe([]);
});

it('refuses to look up indexes for an object type id that is not a ulid', function (): void {
    expect(fn (): mixed => $this->registry->dropObjectTypeIndexes("x' OR 1=1 --"))
        ->toThrow(InvalidArgumentException::class)
        ->and(QueryShape::attemptedBy(function (): void {
            try {
                $this->registry->dropObjectTypeIndexes("x' OR 1=1 --");
            } catch (InvalidArgumentException) {
                return;
            }
        }))->toBeNull();
});

it('reads the supported locales as a deduplicated list of strings', function (): void {
    config(['app.supported_locales' => ['de', 'en', 'de', 7, '']]);

    expect($this->registry->supportedLocales())->toBe(['de', 'en']);
});

it('sends no plain index ddl for a filterable field that only offers equality', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->registry->ensureSortAndFilterIndexes(
        ($this->field)('branche', FieldType::TextShort, ['is_filterable' => true]),
    ));

    expect($shape)->toBeNull();
});

it('still indexes a filterable field that offers range comparisons and any sortable field', function (FieldType $type, array $flags): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->registry->ensureSortAndFilterIndexes(
        ($this->field)('wert', $type, $flags),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toStartWith('CREATE INDEX CONCURRENTLY IF NOT EXISTS ')
        ->and($shape->sql)->toContain($this->registry->indexNameFor(($this->field)('wert', $type)));
})->with([
    'filterable money' => [FieldType::Money, ['is_filterable' => true]],
    'filterable date' => [FieldType::Date, ['is_filterable' => true]],
    'filterable rollup' => [FieldType::Rollup, ['is_filterable' => true]],
    'filterable single select' => [FieldType::SingleSelect, ['is_filterable' => true]],
    'sortable text' => [FieldType::TextShort, ['is_sortable' => true]],
]);

it('keeps the plain index of a filterable single select whose in filter compares the json text', function (): void {
    expect($this->registry->wantsHotFieldIndex(($this->field)('branche', FieldType::SingleSelect, ['is_filterable' => true])))->toBeTrue()
        ->and($this->registry->wantsHotFieldIndex(($this->field)('labels', FieldType::MultiSelect, ['is_filterable' => true])))->toBeFalse();
});

it('wants a plain index for a filterable field exactly when its handler offers a range operator or it is a single select', function (): void {
    $rangeOperators = [
        FilterOperator::GreaterThan,
        FilterOperator::GreaterThanOrEqual,
        FilterOperator::LessThan,
        FilterOperator::LessThanOrEqual,
        FilterOperator::InRange,
    ];
    $handlers = app(FieldTypeRegistry::class);

    $candidates = [];

    foreach (FieldType::cases() as $type) {
        if ($type !== FieldType::Computed) {
            $candidates[] = ($this->field)('wert', $type, ['is_filterable' => true]);
        }
    }

    foreach (FormulaValueType::cases() as $resultType) {
        $candidates[] = ($this->field)('wert', FieldType::Computed, [
            'is_filterable' => true,
            'config' => ['result_type' => $resultType->value],
        ]);
    }

    foreach ($candidates as $field) {
        $offersRange = array_intersect(
            array_map(static fn (FilterOperator $operator): string => $operator->value, $handlers->handlerFor($field->field_type)->filterOperators($field)),
            array_map(static fn (FilterOperator $operator): string => $operator->value, $rangeOperators),
        ) !== [];

        expect($this->registry->wantsHotFieldIndex($field))
            ->toBe($offersRange || $field->field_type === FieldType::SingleSelect, "{$field->field_type->value} ".json_encode($field->config));
    }
});

it('drops the plain index of a field that is now only filterable by equality and creates nothing', function (): void {
    config(['app.supported_locales' => ['de'], 'app.fallback_locale' => 'de']);

    $field = ($this->field)('branche', FieldType::TextShort, ['is_filterable' => true]);
    $base = $this->registry->indexNameFor($field);

    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 0);

    $this->registry->syncSortAndFilterIndexes($field);

    expect(array_column($connection->writtenStatements, 'sql'))->toBe([
        "DROP INDEX CONCURRENTLY IF EXISTS {$base}_de",
        "DROP INDEX CONCURRENTLY IF EXISTS {$base}",
    ]);
});

it('drops the invalid index a failed concurrent build leaves behind, logs it and rethrows a typed exception', function (): void {
    Log::spy();

    $field = ($this->field)('umsatz', FieldType::Money, ['is_sortable' => true]);
    $indexName = $this->registry->indexNameFor($field);

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static function (string $sql): int {
            if (str_starts_with($sql, 'CREATE INDEX')) {
                throw new QueryException('static', $sql, [], new PDOException('canceling statement due to lock timeout'));
            }

            return 0;
        },
    );

    expect(fn (): mixed => $this->registry->ensureHotFieldIndex($field))
        ->toThrow(IndexCreationFailedException::class);

    $statements = array_column($connection->writtenStatements, 'sql');

    expect($statements)->toHaveCount(2)
        ->and($statements[0])->toStartWith("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$indexName}")
        ->and($statements[1])->toBe("DROP INDEX CONCURRENTLY IF EXISTS {$indexName}");

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(static fn (string $message, array $context): bool => $context['index_name'] === $indexName
            && $context['object_type_id'] === ModelStub::ulid('index-object-type')
            && $context['field_key'] === 'umsatz'
            && $context['concurrently'] === true);
});

it('treats a failed locale index build the same way', function (string $method, array $arguments, string $indexName): void {
    Log::spy();

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static function (string $sql): int {
            if (str_starts_with($sql, 'CREATE INDEX')) {
                throw new QueryException('static', $sql, [], new PDOException('could not create unique index'));
            }

            return 0;
        },
    );

    $field = ($this->field)('titel', FieldType::TextShort, ['is_searchable' => true, 'is_sortable' => true, 'is_translatable' => true]);

    expect(fn (): mixed => $this->registry->{$method}($field, ...$arguments))
        ->toThrow(IndexCreationFailedException::class)
        ->and(array_column($connection->writtenStatements, 'sql')[1] ?? null)
        ->toBe('DROP INDEX CONCURRENTLY IF EXISTS '.str_replace('{base}', $this->registry->indexNameFor($field), $indexName));
})->with([
    'locale' => ['ensureLocaleHotFieldIndex', ['de', 'en'], '{base}_de_en'],
]);
