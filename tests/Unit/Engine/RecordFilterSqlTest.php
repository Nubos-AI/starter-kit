<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
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

it('escapes the like wildcards a user typed', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'contains', 'value' => '100%_x'],
        ],
    ]);

    expect($shape->bindings)->toContain('%100\%\_x%');
});

it('casts a numeric field to numeric and guards the cast with a pattern check', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'revenue', 'operator' => 'greaterThan', 'value' => 1000],
        ],
    ]);

    expect($shape->sql)->toContain("CASE WHEN data->>'revenue' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (data->>'revenue')::numeric END")
        ->and($shape->sql)->toContain('> ?')
        ->and($shape->bindings)->toContain(1000);
});

it('renders a range as a bound between clause', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'revenue', 'operator' => 'inRange', 'value' => 10, 'valueTo' => 20],
        ],
    ]);

    expect($shape->sql)->toContain('BETWEEN ? AND ?')
        ->and($shape->bindings)->toBe([10, 20, (string) $this->tenant->getKey()]);
});

it('keeps an or group parenthesised so it cannot widen the surrounding query', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'or',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'equals', 'value' => '76133'],
            ['field' => 'postal_code', 'operator' => 'equals', 'value' => '80331'],
        ],
    ]);

    expect($shape->sql)->toContain("where ((data->>'postal_code') = ? OR (data->>'postal_code') = ?) and \"custom_records\".\"deleted_at\" is null and \"custom_records\".\"tenant_id\" = ?")
        ->and($shape->bindings)->toBe(['76133', '80331', (string) $this->tenant->getKey()]);
});

it('blocks every row when an in filter carries no values', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'postal_code', 'operator' => 'in', 'value' => []],
        ],
    ]);

    expect($shape->sql)->toContain('1 = 0');
});

it('ignores a condition that names a field the caller may not filter on', function (): void {
    $shape = ($this->compiled)([
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'salary', 'operator' => 'equals', 'value' => 'x'],
        ],
    ]);

    expect($shape->sql)->not->toContain('salary')
        ->and($shape->bindings)->toBe([(string) $this->tenant->getKey()]);
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
        ->and($shape->bindings)->toBe(['76133', (string) $this->tenant->getKey()]);
});
