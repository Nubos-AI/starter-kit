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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant));

    /** @var callable(string, FieldType, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, FieldType $type, array $overrides = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('security-field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => ModelStub::ulid('security-object-type'),
            'key' => $key,
            'field_type' => $type,
            'is_filterable' => true,
            'is_sortable' => false,
            'is_encrypted' => false,
            'is_translatable' => false,
            'config' => [],
            ...$overrides,
        ],
    );

    $this->definitions = new EloquentCollection([
        ($this->field)('name', FieldType::TextShort),
        ($this->field)('salary', FieldType::TextShort, ['is_sortable' => true]),
        ($this->field)('secret', FieldType::TextShort, ['is_encrypted' => true, 'is_sortable' => true]),
        ($this->field)('note', FieldType::TextShort, ['is_filterable' => false]),
        ($this->field)('betrag', FieldType::Money),
        ($this->field)('partner', FieldType::RelationHasMany),
    ]);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('security-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'key' => 'deals',
    ], ['fieldDefinitions' => $this->definitions]);

    /** @var callable(list<string>):FieldVisibilityResolver */
    $this->visibility = fn (array $forbidden): FieldVisibilityResolver => new class($forbidden) extends FieldVisibilityResolver
    {
        /**
         * @param  list<string>  $forbidden
         */
        public function __construct(private readonly array $forbidden) {}

        /**
         * @return list<string>
         */
        public function forbiddenReadFieldKeys(User $user, string $objectTypeId): array
        {
            return $this->forbidden;
        }
    };

    /** @var Collection<int, FieldDefinition> */
    $this->allowedFields = $this->definitions
        ->filter(static fn (FieldDefinition $field): bool => $field->is_filterable
            && !$field->is_encrypted
            && $field->key !== 'salary')
        ->values();

    /** @var callable(Builder<CustomRecord>, array<string, mixed>):void */
    $this->apply = function (Builder $query, array $tree): void {
        app(RecordFilterCompiler::class)->applyTree(
            $query,
            $this->allowedFields,
            $tree,
            ($this->visibility)(['salary']),
            $this->objectType,
        );
    };

    /** @var callable(list<array<string, mixed>>):void */
    $this->validateSort = function (array $sorts): void {
        app(FilterTreeValidator::class)->validateSort(
            $sorts,
            $this->allowedFields,
            ($this->visibility)(['salary']),
            $this->objectType,
        );
    };

    /** @var callable():Builder<CustomRecord> */
    $this->newQuery = fn (): Builder => CustomRecord::query();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a predicate on a field the caller may not read', function (): void {
    expect(fn (): mixed => ($this->apply)(($this->newQuery)(), [
        'combinator' => 'and',
        'conditions' => [['field' => 'salary', 'operator' => 'equals', 'value' => '100']],
    ]))->toThrow(AuthorizationException::class);
});

it('leaves the query untouched when it refuses a hidden predicate so no predicate free result escapes', function (): void {
    $query = ($this->newQuery)();
    $before = QueryShape::of($query);

    expect(fn (): mixed => ($this->apply)($query, [
        'combinator' => 'and',
        'conditions' => [['field' => 'salary', 'operator' => 'equals', 'value' => '999']],
    ]))->toThrow(AuthorizationException::class)
        ->and(QueryShape::of($query)->sql)->toBe($before->sql)
        ->and(QueryShape::of($query)->bindings)->toBe($before->bindings);
});

it('refuses a predicate on an encrypted field', function (): void {
    expect(fn (): mixed => ($this->apply)(($this->newQuery)(), [
        'combinator' => 'and',
        'conditions' => [['field' => 'secret', 'operator' => 'equals', 'value' => 'x']],
    ]))->toThrow(AuthorizationException::class);
});

it('refuses a predicate on a field that is readable but not filterable', function (): void {
    expect(fn (): mixed => ($this->apply)(($this->newQuery)(), [
        'combinator' => 'and',
        'conditions' => [['field' => 'note', 'operator' => 'equals', 'value' => 'x']],
    ]))->toThrow(AuthorizationException::class);
});

it('refuses to sort by a hidden or an encrypted field', function (): void {
    expect(fn (): mixed => ($this->validateSort)([['colId' => 'salary', 'sort' => 'asc']]))
        ->toThrow(AuthorizationException::class)
        ->and(fn (): mixed => ($this->validateSort)([['colId' => 'secret', 'sort' => 'desc']]))
        ->toThrow(AuthorizationException::class);
});

it('rejects a sort on a readable field that was never marked sortable', function (): void {
    expect(fn (): mixed => ($this->validateSort)([['colId' => 'name', 'sort' => 'asc']]))
        ->toThrow(InvalidFilterTreeException::class);
});

it('refuses every filter for a caller the guard cannot identify', function (): void {
    auth()->forgetUser();

    expect(fn (): mixed => ($this->apply)(($this->newQuery)(), [
        'combinator' => 'and',
        'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => 'x']],
    ]))->toThrow(AuthorizationException::class);
});

it('rejects a malformed tree on its shape alone and never asks about field visibility', function (Closure $build): void {
    $counting = new class extends FieldVisibilityResolver
    {
        public int $asked = 0;

        public function __construct() {}

        /**
         * @return list<string>
         */
        public function forbiddenReadFieldKeys(User $user, string $objectTypeId): array
        {
            $this->asked++;

            return [];
        }
    };

    expect(fn (): mixed => app(RecordFilterCompiler::class)->applyTree(
        ($this->newQuery)(),
        $this->allowedFields,
        $build(),
        $counting,
        $this->objectType,
    ))->toThrow(InvalidFilterTreeException::class)
        ->and($counting->asked)->toBe(0);
})->with([
    'deeper than five levels' => [function (): array {
        $tree = ['field' => 'name', 'operator' => 'equals', 'value' => 'x'];

        for ($depth = 0; $depth < 7; $depth++) {
            $tree = ['combinator' => 'and', 'conditions' => [$tree]];
        }

        return $tree;
    }],
    'more than fifty conditions' => [function (): array {
        $conditions = [];

        for ($index = 0; $index <= 50; $index++) {
            $conditions[] = ['field' => 'name', 'operator' => 'equals', 'value' => (string) $index];
        }

        return ['combinator' => 'and', 'conditions' => $conditions];
    }],
    'sql payload as a field key' => [fn (): array => [
        'combinator' => 'and',
        'conditions' => [['field' => 'name; DROP TABLE custom_records', 'operator' => 'equals', 'value' => 'x']],
    ]],
    'json arrow as a field key' => [fn (): array => [
        'combinator' => 'and',
        'conditions' => [['field' => "data->>'x'", 'operator' => 'equals', 'value' => 'x']],
    ]],
    'leading digit in the field key' => [fn (): array => [
        'combinator' => 'and',
        'conditions' => [['field' => '1name', 'operator' => 'equals', 'value' => 'x']],
    ]],
    'uppercase field key' => [fn (): array => [
        'combinator' => 'and',
        'conditions' => [['field' => 'Name', 'operator' => 'equals', 'value' => 'x']],
    ]],
]);

it('rejects an unknown but well formed field key', function (): void {
    expect(fn (): mixed => ($this->apply)(($this->newQuery)(), [
        'combinator' => 'and',
        'conditions' => [['field' => 'does_not_exist', 'operator' => 'equals', 'value' => 'x']],
    ]))->toThrow(InvalidFilterTreeException::class);
});

it('rejects an operator the field type cannot carry', function (): void {
    expect(fn (): mixed => ($this->apply)(($this->newQuery)(), [
        'combinator' => 'and',
        'conditions' => [['field' => 'betrag', 'operator' => 'contains', 'value' => 'x']],
    ]))->toThrow(InvalidFilterTreeException::class);
});

it('binds an injection value as a literal instead of writing it into the sql', function (): void {
    $query = ($this->newQuery)();

    ($this->apply)($query, [
        'combinator' => 'and',
        'conditions' => [['field' => 'name', 'operator' => 'equals', 'value' => "' OR 1=1--"]],
    ]);

    $shape = QueryShape::of($query);

    expect($shape->sql)->not->toContain('OR 1=1')
        ->and($shape->bindings)->toContain("' OR 1=1--")
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('adds no partial clause when the tree turns out to be invalid', function (): void {
    $query = ($this->newQuery)();
    $before = QueryShape::of($query);

    expect(fn (): mixed => ($this->apply)($query, [
        'combinator' => 'and',
        'conditions' => [['field' => 'does_not_exist', 'operator' => 'equals', 'value' => 'x']],
    ]))->toThrow(InvalidFilterTreeException::class)
        ->and(QueryShape::of($query)->sql)->toBe($before->sql);
});

it('rejects a relationship predicate whose field names no relationship type', function (): void {
    $query = ($this->newQuery)();
    $before = QueryShape::of($query);

    expect(fn (): mixed => ($this->apply)($query, [
        'combinator' => 'and',
        'conditions' => [['field' => 'partner', 'operator' => 'has']],
    ]))->toThrow(InvalidFilterTreeException::class)
        ->and(fn (): mixed => ($this->apply)($query, [
            'combinator' => 'and',
            'conditions' => [['field' => 'partner', 'operator' => 'hasNot']],
        ]))->toThrow(InvalidFilterTreeException::class)
        ->and(QueryShape::of($query)->sql)->toBe($before->sql);
});
