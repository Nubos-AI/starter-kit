<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Exceptions\Engine\AmbiguousRecordTypeException;
use App\Exceptions\Engine\UnknownRecordFieldException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Support\Facades\App;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_encrypted' => false,
            'is_translatable' => false,
            ...$overrides,
        ],
    );

    /** @var callable(array<string, FieldDefinition>):void */
    $this->declare = function (array $fields): void {
        $registry = Mockery::mock(ObjectTypeRegistry::class);
        $registry->shouldReceive('field')
            ->andReturnUsing(static fn (string $objectTypeId, string $key): ?FieldDefinition => $fields[$key] ?? null);

        app()->instance(ObjectTypeRegistry::class, $registry);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(ObjectTypeRegistry::class);
    App::setLocale((string) config('app.locale'));
});

it('reads a text field through the jsonb text path', function (): void {
    ($this->declare)(['headline' => ($this->field)('headline', FieldType::TextShort)]);

    $shape = QueryShape::of(CustomRecord::query()->ofType($this->objectTypeId)->whereField('headline', 'Acme'));

    expect($shape->sql)->toContain("(data->>'headline') = ?")
        ->and($shape->bindings)->toContain('Acme')
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue();
});

it('compares a number field numerically rather than lexically', function (): void {
    ($this->declare)(['headcount' => ($this->field)('headcount', FieldType::Number)]);

    $shape = QueryShape::of(CustomRecord::query()->ofType($this->objectTypeId)->whereField('headcount', '>', 9));

    expect($shape->sql)->toContain("CASE WHEN data->>'headcount' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (data->>'headcount')::numeric END")
        ->and($shape->sql)->toContain('> ?')
        ->and($shape->bindings)->toContain(9);
});

it('orders a number field numerically rather than lexically', function (): void {
    ($this->declare)(['headcount' => ($this->field)('headcount', FieldType::Number)]);

    $shape = QueryShape::of(CustomRecord::query()->ofType($this->objectTypeId)->orderByField('headcount'));

    expect($shape->sql)->toContain("order by (CASE WHEN data->>'headcount' ~ ")
        ->and($shape->sql)->toContain('::numeric END) asc');
});

it('orders a translatable field by the active locale with the fallback behind it', function (): void {
    ($this->declare)(['label' => ($this->field)('label', FieldType::TextShort, ['is_translatable' => true])]);

    App::setLocale('de');

    $shape = QueryShape::of(CustomRecord::query()->ofType($this->objectTypeId)->orderByField('label'));

    expect($shape->sql)->toContain("COALESCE(data->'label'->>'de', data->'label'->>'en')");
});

it('refuses a field key the object type does not declare', function (): void {
    ($this->declare)([]);

    expect(fn (): mixed => CustomRecord::query()->ofType($this->objectTypeId)->whereField('nope', 'x')->toSql())
        ->toThrow(UnknownRecordFieldException::class);
});

it('refuses to compile a field filter that names no object type at all', function (): void {
    ($this->declare)(['headline' => ($this->field)('headline', FieldType::TextShort)]);

    expect(fn (): mixed => CustomRecord::query()->whereField('headline', 'Acme')->toSql())
        ->toThrow(AmbiguousRecordTypeException::class);
});
