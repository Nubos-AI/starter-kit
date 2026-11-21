<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordSearchPredicate;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticFieldVisibility;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();
    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry);

    $this->predicate = app(RecordSearchPredicate::class);
    $this->searchText = app(RecordSearchText::class);

    /** @var callable(string, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, array $attributes = []): FieldDefinition => ModelStub::make(
        FieldDefinition::class,
        [
            'id' => ModelStub::ulid('predicate-field-'.$key),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => ModelStub::ulid('predicate-deals'),
            'key' => $key,
            'field_type' => FieldType::TextShort,
            'is_searchable' => true,
            'is_translatable' => false,
            'is_encrypted' => false,
            ...$attributes,
        ],
    );

    /** @var callable(list<FieldDefinition>, ?string):QueryShape */
    $this->search = function (array $readable, ?string $term): QueryShape {
        $query = CustomRecord::query();

        $this->predicate->apply($query, new Collection($readable), $term);

        return QueryShape::of($query);
    };
});

afterEach(function (): void {
    StaticFieldVisibility::forget();
    app()->forgetInstance(ObjectTypeRegistry::class);
    app()->forgetInstance(RecordSearchPredicate::class);
    AccessContext::forgetTenant();
    AccessContext::forgetTeam();
});

it('prefilters on the indexed search text and rechecks the readable fields in the same clause', function (): void {
    $shape = ($this->search)([($this->field)('titel'), ($this->field)('email', ['field_type' => FieldType::Email])], 'Aurora');

    expect($shape->sql)->toContain("search_text ILIKE ? AND ((data->>'titel') ILIKE ? OR (data->>'email') ILIKE ? OR record_number ILIKE ? OR external_reference_id ILIKE ?)")
        ->and($shape->bindings)->toBe(['%Aurora%', '%Aurora%', '%Aurora%', '%Aurora%', '%Aurora%', (string) $this->tenant->getKey()]);
});

it('never answers a hit that sits only in a field the user may not read', function (): void {
    $hidden = ($this->field)('gehalt_notiz');
    $readable = ($this->field)('titel');

    $shape = ($this->search)([$readable], 'Aurora');

    expect($this->searchText->expression(new Collection([$readable, $hidden])))->toContain("(data->>'gehalt_notiz')")
        ->and($shape->sql)->not->toContain('gehalt_notiz')
        ->and($shape->sql)->toContain("search_text ILIKE ? AND ((data->>'titel') ILIKE ? OR record_number ILIKE ? OR external_reference_id ILIKE ?)");
});

it('lets a user who reads no searchable field match only on the business keys', function (): void {
    $shape = ($this->search)([], 'Aurora');

    expect($shape->sql)->toContain('search_text ILIKE ? AND (record_number ILIKE ? OR external_reference_id ILIKE ?)')
        ->and($shape->sql)->not->toContain('data->>');
});

it('keeps every recheck arm a contiguous piece of the search text, so the prefilter never drops a row the arms would match', function (): void {
    $fields = [
        ($this->field)('titel'),
        ($this->field)('umsatz', ['field_type' => FieldType::Money]),
        ($this->field)('telefon', ['field_type' => FieldType::Phone]),
        ($this->field)('intern', ['is_searchable' => false]),
    ];

    $shape = ($this->search)($fields, 'Aurora');
    $expression = $this->searchText->expression(new Collection($fields));

    preg_match_all("/\\(data->>'[a-z0-9_]+'\\)|record_number|external_reference_id/", (string) strstr($shape->sql, 'search_text ILIKE ? AND'), $arms);

    expect($arms[0])->toBe(["(data->>'titel')", "(data->>'umsatz')", "(data->>'telefon')", 'record_number', 'external_reference_id']);

    foreach ($arms[0] as $arm) {
        expect($expression)->toContain($arm);
    }
});

it('binds the escaped term for the prefilter exactly as for every arm', function (): void {
    $shape = ($this->search)([($this->field)('titel')], '100%_a');

    expect($shape->bindings)->toBe(['%100\%\_a%', '%100\%\_a%', '%100\%\_a%', '%100\%\_a%', (string) $this->tenant->getKey()]);
});

it('adds no prefilter when the term is too short to search', function (): void {
    expect(($this->search)([($this->field)('titel')], 'ab')->sql)->not->toContain('search_text');
});

it('puts the search text prefilter in front of the identity lookup when its title field is searched', function (?array $titleAttributes, bool $isPrefiltered): void {
    $objectTypeId = ModelStub::ulid('predicate-deals');
    $fields = $titleAttributes === null ? [] : ['name' => ($this->field)('name', ['is_default_column' => true, ...$titleAttributes])];

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [$objectTypeId => new Collection($fields)]));
    StaticFieldVisibility::install([$objectTypeId => array_keys($fields)]);
    app()->forgetInstance(RecordTitleResolver::class);

    $query = CustomRecord::query();
    $applied = app(RecordSearchPredicate::class)->applyIdentity($query, AccessContext::user($this->tenant), $objectTypeId, 'Aurora');
    $shape = QueryShape::of($query);

    $arms = $titleAttributes === null
        ? '(record_number ILIKE ? OR external_reference_id ILIKE ?)'
        : "((data->>'name') ILIKE ? OR record_number ILIKE ? OR external_reference_id ILIKE ?)";

    expect($applied)->toBeTrue()
        ->and($shape->sql)->toContain($isPrefiltered ? "(search_text ILIKE ? AND {$arms})" : $arms)
        ->and(str_contains($shape->sql, 'search_text'))->toBe($isPrefiltered);
})->with([
    'searchable title' => [['is_searchable' => true], true],
    'title outside the search' => [['is_searchable' => false], false],
    'no title at all' => [null, true],
]);
