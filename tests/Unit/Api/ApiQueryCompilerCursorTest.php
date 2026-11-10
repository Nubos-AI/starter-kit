<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Api\ApiQueryCompiler;
use App\Support\Api\FilterableFieldWhitelist;
use App\Support\Engine\IndexRegistry;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $tenantId = (string) $this->tenant->getKey();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $tenantId,
        'slug' => 'companies',
    ]);

    $whitelist = Mockery::mock(FilterableFieldWhitelist::class);
    $whitelist->shouldReceive('sortableKeys')->andReturn(['amount']);
    $whitelist->shouldReceive('filterableKeys')->andReturn([]);
    $whitelist->shouldReceive('includePaths')->andReturn([]);
    $whitelist->shouldReceive('spineColumn')->andReturn(null);

    $this->compiler = new ApiQueryCompiler($whitelist, app(IndexRegistry::class));

    $this->rows = [];
    $this->isTranslatable = false;

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "field_definitions"') => [[
                'id' => ModelStub::ulid('amount-field'),
                'tenant_id' => $tenantId,
                'object_type_id' => ModelStub::ulid('companies'),
                'key' => 'amount',
                'field_type' => 'number',
                'is_translatable' => $this->isTranslatable,
            ]],
            str_contains($sql, 'custom_records') => $this->rows,
            default => [],
        },
    );

    /** @var callable(?string):CursorPaginator<int, CustomRecord> */
    $this->page = function (?string $cursor): mixed {
        $request = Request::create('/api/v1/companies', 'GET', ['sort' => 'amount']);

        return $this->compiler
            ->applyToQuery(CustomRecord::query()->ofType($this->objectType), $request, $this->objectType, AccessContext::user($this->tenant))
            ->cursorPaginate(2, ['*'], 'page[cursor]', $cursor);
    };

    /** @var callable():array{sql: string, bindings: list<mixed>} */
    $this->recordsQuery = function (): array {
        $queries = array_values(array_filter(
            $this->connection->queries,
            static fn (array $query): bool => str_contains($query['sql'], 'from "custom_records"'),
        ));

        return end($queries);
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('continues a custom field sort behind a record without a value instead of dropping the rest of that group', function (): void {
    $cursor = (new Cursor(['api_sort_missing' => 1, 'api_sort_value' => '0', 'id' => ModelStub::ulid('company-b')]))->encode();

    ($this->page)($cursor);

    ['sql' => $sql, 'bindings' => $bindings] = ($this->recordsQuery)();

    expect($sql)->toContain("(((CASE WHEN data->>'amount' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (data->>'amount')::numeric END)) IS NULL)::int > ?")
        ->and($sql)->toContain("COALESCE((CASE WHEN data->>'amount' ~ '^-?[0-9]+(\\.[0-9]+)?$' THEN (data->>'amount')::numeric END), 0) > ?")
        ->and($bindings)->toContain(ModelStub::ulid('company-b'));
});

it('orders the records without a sort value behind the others so the cursor never skips them', function (): void {
    $this->rows = [
        ['id' => ModelStub::ulid('company-a'), 'tenant_id' => (string) $this->tenant->getKey(), 'api_sort_missing' => 0, 'api_sort_value' => '5'],
        ['id' => ModelStub::ulid('company-b'), 'tenant_id' => (string) $this->tenant->getKey(), 'api_sort_missing' => 1, 'api_sort_value' => '0'],
        ['id' => ModelStub::ulid('company-c'), 'tenant_id' => (string) $this->tenant->getKey(), 'api_sort_missing' => 1, 'api_sort_value' => '0'],
    ];

    $next = ($this->page)(null)->nextCursor();

    expect(($this->recordsQuery)()['sql'])->toContain('order by "api_sort_missing" asc, "api_sort_value" asc, "id" asc')
        ->and($next)->not->toBeNull()
        ->and($next->parameter('api_sort_missing'))->toBe(1)
        ->and($next->parameter('api_sort_value'))->not->toBeNull()
        ->and($next->parameter('id'))->toBe(ModelStub::ulid('company-b'));
});

it('falls back to an empty text for a translatable number field whose sort value is read as text', function (): void {
    $this->isTranslatable = true;

    ($this->page)(null);

    expect(($this->recordsQuery)()['sql'])->toContain("data->'amount'->>")
        ->and(($this->recordsQuery)()['sql'])->toContain("), '') as api_sort_value")
        ->and(($this->recordsQuery)()['sql'])->not->toContain('), 0) as api_sort_value');
});
