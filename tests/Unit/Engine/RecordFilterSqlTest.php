<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\RecordFilterCompiler;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(string, FieldType):FieldDefinition */
    $this->field = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'key' => $key,
        'field_type' => $type,
        'is_filterable' => true,
        'is_translatable' => false,
        'is_encrypted' => false,
    ]);

    $this->fields = new Collection([
        ($this->field)('postal_code', FieldType::TextShort),
        ($this->field)('revenue', FieldType::Number),
        ($this->field)('labels', FieldType::MultiSelect),
    ]);

    /** @var callable(array<string, mixed>):QueryShape */
    $this->compiled = function (array $tree): QueryShape {
        $query = CustomRecord::query();

        app(RecordFilterCompiler::class)->applyValidatedTree($query, $this->fields, $tree);

        return QueryShape::of($query);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads a text field through the jsonb text path and compares it case insensitively', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'startsWith', 'value' => '76'],
        ],
    ]);

    expect($shape->sql)->toContain("((data->>'postal_code') ILIKE ?")
        ->and($shape->bindings)->toContain('76%');
});

it('casts a numeric field to numeric and guards the cast with a pattern check', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'revenue', 'operator' => 'greaterThan', 'value' => 1000],
        ],
    ]);

    $numeric = app(IndexRegistry::class)->indexExpression($this->fields->firstWhere('key', 'revenue'), 'revenue');

    expect($shape->sql)->toContain("where ({$numeric} > ?)")
        ->and($shape->bindings)->toContain(1000);
});

it('keeps the tenant condition next to the filter instead of replacing it', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'equals', 'value' => '76133'],
        ],
    ]);

    expect($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue()
        ->and($shape->bindings)->toBe(['postal_code', '76133', (string) $this->tenant->getKey()]);
});

it('matches a multi select that holds any of the chosen options', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'labels', 'operator' => 'in', 'value' => ['Heiß', 'Warm']],
        ],
    ]);

    expect($shape->sql)->toContain("where (EXISTS (SELECT 1 FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(data->'labels') = 'array' THEN data->'labels' ELSE '[]'::jsonb END) AS selected(option) WHERE selected.option IN (?, ?)))")
        ->and($shape->bindings)->toBe(['Heiß', 'Warm', (string) $this->tenant->getKey()]);
});

it('keeps a multi select out that holds any of the excluded options and, like a single select, one that holds none', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'labels', 'operator' => 'notIn', 'value' => ['Kalt']],
        ],
    ]);

    expect($shape->sql)->toContain("where (((data->'labels') IS NOT NULL AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements_text(CASE WHEN jsonb_typeof(data->'labels') = 'array' THEN data->'labels' ELSE '[]'::jsonb END) AS selected(option) WHERE selected.option IN (?))))")
        ->and($shape->bindings)->toBe(['Kalt', (string) $this->tenant->getKey()]);
});

it('blocks every row when an in filter on a multi select carries no values', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'labels', 'operator' => 'in', 'value' => []],
        ],
    ]);

    expect($shape->sql)->toContain('1 = 0');
});

it('matches equality on a field stored as a json string through containment so the gin index applies', function (FieldType $type): void {
    $this->fields = new Collection([($this->field)('industry', $type)]);

    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'industry', 'operator' => 'equals', 'value' => 'Bildung'],
        ],
    ]);

    expect($shape->sql)->toContain('where (data @> jsonb_build_object(?::text, ?::text))')
        ->and($shape->bindings)->toBe(['industry', 'Bildung', (string) $this->tenant->getKey()]);
})->with([
    'short text' => [FieldType::TextShort],
    'long text' => [FieldType::TextLong],
    'email' => [FieldType::Email],
    'phone' => [FieldType::Phone],
    'url' => [FieldType::Url],
]);

it('keeps comparing the json text for equality on a field whose stored json type is not fixed', function (FieldType $type, string $value): void {
    $this->fields = new Collection([($this->field)('industry', $type)]);

    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'industry', 'operator' => 'equals', 'value' => $value],
        ],
    ]);

    expect($shape->sql)->toContain('where ((data->>\'industry\') = ?)')
        ->and($shape->bindings)->toBe([$value, (string) $this->tenant->getKey()]);
})->with([
    'single select' => [FieldType::SingleSelect, 'Bildung'],
    'multi select' => [FieldType::MultiSelect, 'Bildung'],
    'boolean' => [FieldType::Boolean, 'Bildung'],
    'date' => [FieldType::Date, '2026-10-01'],
    'date time' => [FieldType::DateTime, '2026-10-01 08:30:00'],
    'computed' => [FieldType::Computed, 'Bildung'],
    'rollup' => [FieldType::Rollup, 'Bildung'],
]);

it('keeps the guarded numeric cast for equality on a numeric field', function (FieldType $type): void {
    $this->fields = new Collection([($this->field)('revenue', $type)]);

    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'revenue', 'operator' => 'equals', 'value' => 1000],
        ],
    ]);

    $numeric = app(IndexRegistry::class)->indexExpression($this->fields->first(), 'revenue');

    expect($shape->sql)->toContain("where ({$numeric} = ?)")
        ->and($shape->bindings)->toBe([1000, (string) $this->tenant->getKey()]);
})->with([
    'number' => [FieldType::Number],
    'decimal' => [FieldType::Decimal],
    'money' => [FieldType::Money],
]);

it('keeps comparing the json text when the equality value is not a string so a stored json null never matches', function (mixed $value): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'equals', 'value' => $value],
        ],
    ]);

    expect($shape->sql)->toContain('where ((data->>\'postal_code\') = ?)')
        ->and($shape->bindings)->toBe([$value, (string) $this->tenant->getKey()]);
})->with([
    'null' => [null],
    'integer' => [76133],
]);

it('keeps the text path for equality on an encrypted or a translatable text field', function (array $overrides, string $expression): void {
    $field = ($this->field)('headline', FieldType::TextShort);
    $field->forceFill($overrides);
    $this->fields = new Collection([$field]);
    config(['app.fallback_locale' => 'de']);
    app()->setLocale('de');

    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'headline', 'operator' => 'equals', 'value' => 'Neu'],
        ],
    ]);

    expect($shape->sql)->toContain("where ({$expression} = ?)")
        ->and($shape->bindings)->toBe(['Neu', (string) $this->tenant->getKey()]);
})->with([
    'encrypted' => [['is_encrypted' => true], "(data->>'headline')"],
    'translatable' => [['is_translatable' => true], "(data->'headline'->>'de')"],
]);

it('keeps a negated equality on the json text path', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'notEqual', 'value' => '76133'],
        ],
    ]);

    expect($shape->sql)->toContain('where ((data->>\'postal_code\') <> ?)')
        ->and($shape->bindings)->toBe(['76133', (string) $this->tenant->getKey()]);
});

it('compares a virtual field through its supplied expression instead of containment', function (): void {
    $query = CustomRecord::query();

    app(RecordFilterCompiler::class)->applyValidatedTree($query, $this->fields, [
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'equals', 'value' => '76133'],
        ],
    ], ['postal_code' => ['sql' => 'upper(data->>\'postal_code\')', 'bindings' => []]]);

    $shape = QueryShape::of($query);

    expect($shape->sql)->toContain('where (upper(data->>\'postal_code\') = ?)')
        ->and($shape->bindings)->toBe(['76133', (string) $this->tenant->getKey()]);
});

it('matches an equality from the grid filter model through containment as well', function (): void {
    $query = CustomRecord::query();

    app(RecordFilterCompiler::class)->apply($query, $this->fields, [
        'postal_code' => ['type' => 'equals', 'filter' => '76133'],
    ]);

    $shape = QueryShape::of($query);

    expect($shape->sql)->toContain('where data @> jsonb_build_object(?::text, ?::text)')
        ->and($shape->bindings)->toBe(['postal_code', '76133', (string) $this->tenant->getKey()]);
});
