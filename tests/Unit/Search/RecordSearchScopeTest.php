<?php

declare(strict_types=1);

use App\Enums\Api\SearchSource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use App\Support\Api\RecordSearchResult;
use App\Support\Api\RecordSearchService;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Search\SearchVisibilityFilter;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Laravel\Sanctum\PersonalAccessToken;
use Meilisearch\Exceptions\CommunicationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticFieldVisibility;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->companies = ModelStub::ulid('companies');
    $this->contacts = ModelStub::ulid('contacts');

    $this->service = new RecordSearchService(app(FieldTypeRegistry::class), new ApiAbilityMap);

    $this->objectTypes = ModelStub::collection(ObjectType::class, [
        ['id' => $this->companies, 'tenant_id' => $this->tenant->getKey(), 'slug' => 'companies'],
        ['id' => $this->contacts, 'tenant_id' => $this->tenant->getKey(), 'slug' => 'contacts'],
    ]);

    /** @var callable(string, string):array<string, mixed> */
    $this->field = fn (string $objectTypeId, string $key): array => [
        'id' => ModelStub::ulid($objectTypeId.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $objectTypeId,
        'key' => $key,
        'is_searchable' => true,
        'is_encrypted' => false,
    ];

    $this->fields = ModelStub::collection(FieldDefinition::class, [
        ($this->field)($this->companies, 'name'),
        ($this->field)($this->companies, 'city'),
        ($this->field)($this->contacts, 'name'),
    ]);

    /** @var callable(list<string>):User */
    $this->caller = function (array $abilities): User {
        $user = AccessContext::user($this->tenant);
        $token = ModelStub::make(PersonalAccessToken::class, ['id' => 7]);
        $token->abilities = $abilities;

        return $user->withAccessToken($token);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    StaticFieldVisibility::forget();
});

it('searches only the object types the caller may view and the token carries an ability for', function (): void {
    AccessContext::grant('companies.view', 'contacts.view');
    StaticFieldVisibility::install([
        $this->companies => ['name', 'city'],
        $this->contacts => ['name'],
    ]);

    $groups = $this->service->searchGroups(($this->caller)(['companies:read']), $this->objectTypes, $this->fields);

    expect($groups)->toBe([
        ['objectTypeIds' => [$this->companies], 'attributes' => ['name', 'city']],
    ]);
});

it('searches nothing at all when the token carries only a write ability', function (): void {
    AccessContext::grant('companies.view', 'contacts.view');
    StaticFieldVisibility::install([
        $this->companies => ['name', 'city'],
        $this->contacts => ['name'],
    ]);

    expect($this->service->searchGroups(($this->caller)(['companies:write']), $this->objectTypes, $this->fields))->toBe([]);
});

it('drops an object type the token covers but the caller may not view', function (): void {
    AccessContext::grant('contacts.view');
    StaticFieldVisibility::install([
        $this->companies => ['name', 'city'],
        $this->contacts => ['name'],
    ]);

    $groups = $this->service->searchGroups(($this->caller)(['records:read']), $this->objectTypes, $this->fields);

    expect($groups)->toBe([
        ['objectTypeIds' => [$this->contacts], 'attributes' => ['name']],
    ]);
});

it('merges the unrestricted object types into one group and keeps every narrowed type on its own', function (): void {
    AccessContext::grant('companies.view', 'contacts.view');
    StaticFieldVisibility::install([
        $this->companies => ['name'],
        $this->contacts => ['name'],
    ]);

    $groups = $this->service->searchGroups(($this->caller)(['records:read']), $this->objectTypes, $this->fields);

    expect($groups)->toBe([
        ['objectTypeIds' => [$this->contacts], 'attributes' => ['name']],
        ['objectTypeIds' => [$this->companies], 'attributes' => ['name']],
    ]);
});

it('never offers a field the caller may not read as a searchable attribute', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => ['name']]);

    $groups = $this->service->searchGroups(($this->caller)(['records:read']), $this->objectTypes, $this->fields);

    expect($groups[0]['attributes'])->toBe(['name'])
        ->and($groups[0]['attributes'])->not->toContain('city');
});

it('sends the server built tenant filter and only the readable attributes to the index', function (): void {
    $user = ($this->caller)(['records:read']);

    $builder = $this->service->buildIndexQuery($user, 'meier', [
        'objectTypeIds' => [$this->companies],
        'attributes' => ['name'],
    ]);

    expect($builder->options['filter'])->toBe(SearchVisibilityFilter::forObjectTypes($user, [$this->companies]))
        ->and($builder->options['attributesToSearchOn'])->toBe(['name'])
        ->and($builder->query)->toBe('meier')
        ->and($builder->limit)->toBe(25);
});

it('narrows the hydrating index query to the object types of its own group', function (): void {
    $user = ($this->caller)(['records:read']);

    $builder = $this->service->buildIndexQuery($user, 'meier', [
        'objectTypeIds' => [$this->companies],
        'attributes' => ['name'],
    ]);

    $hydrating = ($builder->queryCallback)(CustomRecord::query());

    expect($hydrating)->toBeInstanceOf(EloquentBuilder::class);

    $shape = QueryShape::of($hydrating);

    expect($shape->sql)->toContain('"object_type_id" in (?)')
        ->and($shape->bindings)->toContain($this->companies)
        ->and($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue();
});

it('restricts the fallback query to the attributes of its group and to searchable plain fields', function (): void {
    $shape = QueryShape::of($this->service->buildDatabaseQuery('meier', [
        ['objectTypeIds' => [$this->companies], 'attributes' => ['name']],
    ]));

    expect($shape->sql)->toContain('"field_definitions"."key" in (?)')
        ->and($shape->sql)->toContain('"field_definitions"."is_searchable" = ?')
        ->and($shape->sql)->toContain('"field_definitions"."is_encrypted" = ?')
        ->and($shape->sql)->toContain('"field_definitions"."is_translatable" = ?')
        ->and($shape->sql)->toContain('"field_definitions"."deleted_at" is null')
        ->and($shape->bindings)->toContain('name')
        ->and($shape->bindings)->toContain('%meier%');
});

it('keeps the tenant boundary and the soft delete filter on the fallback query', function (): void {
    $shape = QueryShape::of($this->service->buildDatabaseQuery('meier', [
        ['objectTypeIds' => [$this->companies], 'attributes' => ['name']],
    ]));

    expect($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('custom_records'))->toBeTrue()
        ->and($shape->hasColumnCondition('custom_records', 'object_type_id'))->toBeTrue()
        ->and($shape->bindings)->toContain($this->companies);
});

it('escapes the like wildcards a caller typed into the search term', function (): void {
    $shape = QueryShape::of($this->service->buildDatabaseQuery('100%_x', [
        ['objectTypeIds' => [$this->companies], 'attributes' => ['name']],
    ]));

    expect($shape->bindings)->toContain('%100\%\_x%');
});

it('keeps every group of the fallback query in its own parenthesised branch', function (): void {
    $shape = QueryShape::of($this->service->buildDatabaseQuery('meier', [
        ['objectTypeIds' => [$this->companies], 'attributes' => ['name']],
        ['objectTypeIds' => [$this->contacts], 'attributes' => ['city']],
    ]));

    expect(substr_count($shape->sql, 'exists (select 1 from "field_definitions"'))->toBe(2)
        ->and($shape->bindings)->toContain($this->companies)
        ->and($shape->bindings)->toContain($this->contacts)
        ->and($shape->bindings)->toContain('city');
});

it('answers an empty result without touching the database when nothing is searchable', function (): void {
    AccessContext::grant();
    StaticFieldVisibility::install([]);

    $user = ($this->caller)(['records:read']);
    $result = null;

    $reachedTheDatabase = QueryShape::attemptedBy(function () use ($user, &$result): void {
        $result = $this->service->search($user, 'meier', $this->objectTypes, $this->fields);
    });

    expect($reachedTheDatabase)->toBeNull()
        ->and($result)->toBeInstanceOf(RecordSearchResult::class)
        ->and($result->hits)->toBeInstanceOf(EloquentCollection::class)
        ->and($result->hits)->toHaveCount(0)
        ->and($result->source)->toBe(SearchSource::Index);
});

it('falls back to the database path with the same groups once the index is unreachable', function (): void {
    AccessContext::grant('companies.view');
    StaticFieldVisibility::install([$this->companies => ['name']]);

    $service = new class(app(FieldTypeRegistry::class), new ApiAbilityMap) extends RecordSearchService
    {
        public function searchViaIndex(User $user, string $term, array $groups): EloquentCollection
        {
            throw new CommunicationException('the index is unreachable');
        }
    };

    $shape = QueryShape::attemptedBy(fn (): RecordSearchResult => $service->search(
        ($this->caller)(['records:read']),
        'meier',
        $this->objectTypes,
        $this->fields,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"field_definitions"')
        ->and($shape->bindings)->toContain('%meier%')
        ->and($shape->bindings)->toContain($this->companies);
});
