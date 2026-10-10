<?php

declare(strict_types=1);

use App\Support\Search\SearchVisibilityFilter;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->viewer = AccessContext::user($this->tenant);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('constrains the index to the bound tenant and to nothing else', function (): void {
    $filter = SearchVisibilityFilter::for($this->viewer);

    expect($filter)->toBe('tenant_id = "'.$this->tenant->getKey().'"')
        ->and($filter)->not->toContain('owner_id')
        ->and($filter)->not->toContain('team_id')
        ->and($filter)->not->toContain('visibility');
});

it('falls back to the tenant of the viewer when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(SearchVisibilityFilter::for($this->viewer))->toBe('tenant_id = "'.$this->tenant->getKey().'"');
});

it('never widens past the tenant when a foreign tenant is bound', function (): void {
    $other = AccessContext::tenant('other-tenant');

    expect(SearchVisibilityFilter::for($this->viewer))
        ->toBe('tenant_id = "'.$other->getKey().'"')
        ->and((string) $other->getKey())->not->toBe((string) $this->tenant->getKey());
});

it('adds the object type list next to the tenant clause and never instead of it', function (): void {
    $companies = ModelStub::ulid('companies');
    $contacts = ModelStub::ulid('contacts');

    expect(SearchVisibilityFilter::forObjectTypes($this->viewer, [$companies, $contacts]))
        ->toBe('tenant_id = "'.$this->tenant->getKey().'" AND object_type_id IN ["'.$companies.'", "'.$contacts.'"]');
});

it('keeps the tenant clause even when the caller passes an empty object type list', function (): void {
    expect(SearchVisibilityFilter::forObjectTypes($this->viewer, []))
        ->toBe('tenant_id = "'.$this->tenant->getKey().'" AND object_type_id IN []');
});

it('escapes a quote a caller smuggled into an object type id so the filter cannot be broken out of', function (): void {
    $filter = SearchVisibilityFilter::forObjectTypes($this->viewer, ['x" OR tenant_id = "foreign']);

    expect($filter)->toContain('object_type_id IN ["x\\" OR tenant_id = \\"foreign"]')
        ->and(substr_count($filter, 'tenant_id = "'))->toBe(1);
});
