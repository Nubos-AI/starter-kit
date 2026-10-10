<?php

declare(strict_types=1);

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\ReportActionRefusalReason;
use App\Enums\Reports\ReportExecutionMode;
use App\Http\Resources\Api\V1\ReportResource as ApiReportResource;
use App\Http\Resources\Reports\ReportResource;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'name' => 'Deals',
    ]);

    $this->filterTree = [
        'combinator' => 'and',
        'conditions' => [
            ['field' => 'sales_region', 'operator' => 'equals', 'value' => 'north'],
            ['field' => 'payroll_total', 'operator' => 'greaterThan', 'value' => 100],
            [
                'combinator' => 'or',
                'conditions' => [
                    ['field' => 'payroll_total', 'operator' => 'lessThan', 'value' => 10],
                    ['field' => 'ordered_quantity', 'operator' => 'equals', 'value' => 3],
                ],
            ],
        ],
    ];

    /** @var callable(array<string, mixed>):Report */
    $this->report = fn (array $overrides = []): Report => ModelStub::make(
        Report::class,
        [
            'id' => ModelStub::ulid('report'),
            'tenant_id' => $this->tenant->getKey(),
            'owner_id' => ModelStub::ulid('owner'),
            'object_type_id' => $this->objectType->getKey(),
            'name' => 'Pipeline',
            'description' => 'A description',
            'filter_definition' => $this->filterTree,
            'aggregation_type' => AggregationType::Count->value,
            'aggregation_field_key' => null,
            'group_by_field_key' => 'sales_region',
            'group_by_bucket' => null,
            'series_field_key' => null,
            'chart_type' => ChartType::Bar->value,
            'execution_mode' => ReportExecutionMode::Viewer->value,
            'updated_at' => '2026-09-23 08:30:00',
            ...$overrides,
        ],
        ['objectType' => $this->objectType],
    );

    /** @var callable(?User):Request */
    $this->requestOf = static function (?User $user): Request {
        $request = Request::create('/reports', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };

    /** @var callable(array<string, mixed>):list<string> */
    $this->filterFieldsOf = static function (array $tree) use (&$collect): array {
        $collect ??= static function (array $node) use (&$collect): array {
            if (!is_array($node['conditions'] ?? null)) {
                return [];
            }

            $keys = [];

            foreach ($node['conditions'] as $child) {
                $keys = array_key_exists('conditions', $child)
                    ? [...$keys, ...$collect($child)]
                    : [...$keys, $child['field']];
            }

            return $keys;
        };

        return $collect($tree);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('withholds every filter condition on a field the viewer may not read, at any nesting depth', function (): void {
    new FakeFieldVisibilityResolver(['payroll_total']);
    GateSpy::allowing('view');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $payload = (new ReportResource(($this->report)()))->resolve(($this->requestOf)($user));

    expect(($this->filterFieldsOf)($payload['filter_definition']))
        ->toBe(['sales_region', 'ordered_quantity']);
});

it('keeps every filter condition once the viewer may read all of them', function (): void {
    new FakeFieldVisibilityResolver;
    GateSpy::allowing('view');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $payload = (new ReportResource(($this->report)()))->resolve(($this->requestOf)($user));

    expect(($this->filterFieldsOf)($payload['filter_definition']))
        ->toBe(['sales_region', 'payroll_total', 'payroll_total', 'ordered_quantity']);
});

it('hands out an empty filter definition to a caller who is not signed in', function (): void {
    new FakeFieldVisibilityResolver(['payroll_total']);
    GateSpy::allowing();

    $payload = (new ReportResource(($this->report)()))->resolve(($this->requestOf)(null));

    expect($payload['filter_definition'])->toBe([]);
});

it('withholds the same conditions through the api resource', function (): void {
    new FakeFieldVisibilityResolver(['payroll_total']);

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $payload = (new ApiReportResource(($this->report)()))->resolve(($this->requestOf)($user));

    /** @var array<string, mixed> $attributes */
    $attributes = $payload['attributes'];

    expect(($this->filterFieldsOf)((array) $attributes['filterDefinition']))
        ->toBe(['sales_region', 'ordered_quantity']);
});

it('hands out an empty filter definition through the api resource for an unauthenticated caller', function (): void {
    new FakeFieldVisibilityResolver(['payroll_total']);

    $payload = (new ApiReportResource(($this->report)()))->resolve(($this->requestOf)(null));

    /** @var array<string, mixed> $attributes */
    $attributes = $payload['attributes'];

    expect((array) $attributes['filterDefinition'])->toBe([]);
});

it('marks a foreign report read only and names the owner as the reason', function (): void {
    new FakeFieldVisibilityResolver;
    GateSpy::allowing('view');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $payload = (new ReportResource(($this->report)()))->resolve(($this->requestOf)($user));

    expect($payload['can_update'])->toBeFalse()
        ->and($payload['can_delete'])->toBeFalse()
        ->and($payload['is_owner'])->toBeFalse()
        ->and($payload['update_reason'])->toBe(ReportActionRefusalReason::NotOwner->value)
        ->and($payload['delete_reason'])->toBe(ReportActionRefusalReason::NotOwner->value);
});

it('names the object type as the reason when the viewer may not even read the report', function (): void {
    new FakeFieldVisibilityResolver;
    GateSpy::allowing();

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $payload = (new ReportResource(($this->report)()))->resolve(($this->requestOf)($user));

    expect($payload['can_update'])->toBeFalse()
        ->and($payload['update_reason'])->toBe(ReportActionRefusalReason::ObjectTypeNotPermitted->value)
        ->and($payload['delete_reason'])->toBe(ReportActionRefusalReason::ObjectTypeNotPermitted->value);
});

it('carries both abilities and no reason on a report the viewer governs', function (): void {
    new FakeFieldVisibilityResolver;
    GateSpy::allowing('view', 'update', 'delete');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));
    $report = ($this->report)(['owner_id' => $user->getKey()]);

    $payload = (new ReportResource($report))->resolve(($this->requestOf)($user));

    expect($payload['can_update'])->toBeTrue()
        ->and($payload['can_delete'])->toBeTrue()
        ->and($payload['is_owner'])->toBeTrue()
        ->and($payload['update_reason'])->toBeNull()
        ->and($payload['delete_reason'])->toBeNull();
});

it('states every refusal reason as a snake case token rather than a sentence', function (): void {
    foreach (ReportActionRefusalReason::cases() as $case) {
        expect($case->value)->toMatch('/^[a-z][a-z0-9_]*$/');
    }
});

it('renders the timestamps as iso 8601 and the key as a string', function (): void {
    new FakeFieldVisibilityResolver;
    GateSpy::allowing('view');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $payload = (new ReportResource(($this->report)()))->resolve(($this->requestOf)($user));

    expect($payload['id'])->toBeString()
        ->and($payload['updated_at'])->toBe('2026-09-23T08:30:00+00:00')
        ->and($payload['object_type'])->toBe([
            'id' => (string) $this->objectType->getKey(),
            'slug' => 'deals',
            'name' => 'Deals',
        ]);
});

it('never reaches the database while rendering a report row', function (): void {
    new FakeFieldVisibilityResolver(['payroll_total']);
    GateSpy::allowing('view');

    $user = AccessContext::actAs(AccessContext::user($this->tenant));

    $reached = QueryShape::attemptedBy(
        fn (): array => (new ReportResource(($this->report)()))->resolve(($this->requestOf)($user)),
    );

    expect($reached)->toBeNull();
});
