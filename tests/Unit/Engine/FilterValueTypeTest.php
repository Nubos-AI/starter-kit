<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\RecordFilterCompiler;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->tenant = AccessContext::tenant();

    /** @var callable(string, FieldType):FieldDefinition */
    $this->field = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('value-field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('value-object-type'),
        'key' => $key,
        'field_type' => $type,
        'is_filterable' => true,
        'is_sortable' => true,
        'is_encrypted' => false,
        'is_translatable' => false,
        'config' => [],
    ]);

    $this->fields = new EloquentCollection([
        ($this->field)('name', FieldType::TextShort),
        ($this->field)('revenue', FieldType::Number),
        ($this->field)('closed_on', FieldType::Date),
        ($this->field)('labels', FieldType::MultiSelect),
    ]);

    /** @var callable(array<string, mixed>):void */
    $this->validate = fn (array $condition): mixed => app(FilterTreeValidator::class)->validateDefinition(
        ['combinator' => 'and', 'conditions' => [$condition]],
        $this->fields,
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a filter value its field cannot compare with a German reason', function (array $condition): void {
    expect(fn () => ($this->validate)($condition))
        ->toThrow(InvalidFilterTreeException::class, 'Ein Filterwert passt nicht zum Typ seines Feldes. Bitte prüfen Sie den Filter.');
})->with([
    'a list for a single value operator' => [['field' => 'name', 'operator' => 'equals', 'value' => ['a', 'b']]],
    'text against a number field' => [['field' => 'revenue', 'operator' => 'greaterThan', 'value' => 'viel']],
    'a boolean against a number field' => [['field' => 'revenue', 'operator' => 'equals', 'value' => true]],
    'text in the upper range bound' => [['field' => 'revenue', 'operator' => 'inRange', 'value' => 1, 'valueTo' => 'abc']],
    'a value that is no date' => [['field' => 'closed_on', 'operator' => 'lessThan', 'value' => 'irgendwann']],
    'a keyed map for a list operator' => [['field' => 'labels', 'operator' => 'in', 'value' => ['first' => 'a']]],
    'a nested list for a list operator' => [['field' => 'name', 'operator' => 'in', 'value' => [['a']]]],
]);

it('accepts every filter value its field can compare', function (array $condition): void {
    ($this->validate)($condition);

    expect(true)->toBeTrue();
})->with([
    'text' => [['field' => 'name', 'operator' => 'contains', 'value' => 'Acme']],
    'a number for a text field' => [['field' => 'name', 'operator' => 'equals', 'value' => 76133]],
    'a numeric string' => [['field' => 'revenue', 'operator' => 'greaterThan', 'value' => '1000.50']],
    'a range of numbers' => [['field' => 'revenue', 'operator' => 'inRange', 'value' => 1, 'valueTo' => 5]],
    'an iso date' => [['field' => 'closed_on', 'operator' => 'lessThan', 'value' => '2026-10-01']],
    'a list of options' => [['field' => 'labels', 'operator' => 'in', 'value' => ['a', 'b']]],
    'an empty value' => [['field' => 'name', 'operator' => 'equals', 'value' => null]],
]);

it('neutralises a stored condition whose value does not fit instead of failing the whole list', function (): void {
    Log::spy();

    $query = CustomRecord::query();

    app(RecordFilterCompiler::class)->applyValidatedTree($query, $this->fields, [
        'combinator' => 'or',
        'conditions' => [
            ['field' => 'revenue', 'operator' => 'greaterThan', 'value' => 'viel'],
            ['field' => 'name', 'operator' => 'equals', 'value' => ['a', 'b']],
            ['field' => 'name', 'operator' => 'contains', 'value' => 'Acme'],
        ],
    ]);

    $shape = QueryShape::of($query);

    expect($shape->sql)->toContain("((FALSE) OR (FALSE) OR (data->>'name') ILIKE ?)")
        ->and($shape->bindings)->toBe(['%Acme%', (string) $this->tenant->getKey()]);

    Log::shouldHaveReceived('warning')
        ->with('A stored filter condition carries a value its field cannot compare and matches nothing.', ['field_key' => 'revenue', 'operator' => 'greaterThan'])
        ->once();
});

it('neutralises an incomparable value of a saved segment on the read path instead of refusing the whole list', function (): void {
    AccessContext::actAs(AccessContext::user($this->tenant));
    Log::spy();

    $objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('value-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ], ['fieldDefinitions' => $this->fields]);

    $visibility = new class extends FieldVisibilityResolver
    {
        public function __construct() {}

        /**
         * @return list<string>
         */
        public function forbiddenReadFieldKeys(User $user, string $objectTypeId): array
        {
            return [];
        }
    };

    $query = CustomRecord::query();

    app(RecordFilterCompiler::class)->applyTree($query, $this->fields, [
        'combinator' => 'and',
        'conditions' => [['field' => 'revenue', 'operator' => 'greaterThan', 'value' => 'viel']],
    ], $visibility, $objectType);

    expect(QueryShape::of($query)->sql)->toContain('((FALSE))');
});

it('refuses a grid filter value its field cannot compare on the filter model', function (array $filterModel): void {
    $query = CustomRecord::query();

    try {
        app(RecordFilterCompiler::class)->apply($query, $this->fields, $filterModel);
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['filterModel' => ['Ein Filterwert passt nicht zum Typ seines Feldes. Bitte prüfen Sie den Filter.']]);

        return;
    }

    $this->fail('The grid filter was compiled although its value does not fit the field.');
})->with([
    'text against a number field' => [['revenue' => ['type' => 'greaterThan', 'filter' => 'viel']]],
    'a list for a single value operator' => [['name' => ['type' => 'equals', 'filter' => ['a']]]],
]);
