<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = (string) AccessContext::tenant('query-shape-tenant')->getKey();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('counts a tenant condition only while no or condition can lift it', function (Closure $narrow, bool $isScoped): void {
    $query = CustomRecord::query();
    $narrow($query);

    expect(QueryShape::of($query)->isScopedToTenant('custom_records', $this->tenantId))->toBe($isScoped);
})->with([
    'tenant scope alone' => [static fn (Builder $query): Builder => $query, true],
    'tenant scope next to a further and condition' => [static fn (Builder $query): Builder => $query->where('object_type_id', ModelStub::ulid('type')), true],
    'or condition beside the tenant condition' => [static fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('custom_records.tenant_id', ModelStub::ulid('query-shape-tenant'))->orWhereRaw('1 = 1'), false],
    'or condition beside the global tenant scope is nested away by the framework' => [static fn (Builder $query): Builder => $query->where('id', 'x')->orWhereRaw('1 = 1'), true],
    'or condition around a group holding the tenant scope' => [static fn (Builder $query): Builder => $query->withoutGlobalScopes()->where(static fn (Builder $inner): Builder => $inner->where('custom_records.tenant_id', ModelStub::ulid('query-shape-tenant'))->where('id', 'x'))->orWhere('id', 'y'), false],
    'or condition kept inside its own group' => [static fn (Builder $query): Builder => $query->where(static fn (Builder $inner): Builder => $inner->where('id', 'x')->orWhere('id', 'y')), true],
    'unqualified tenant condition on a query of the table alone' => [static fn (Builder $query): Builder => $query->withoutGlobalScopes()->where('tenant_id', ModelStub::ulid('query-shape-tenant')), true],
    'unqualified tenant condition that a join makes ambiguous' => [static fn (Builder $query): Builder => $query->withoutGlobalScopes()->join('object_types', 'object_types.id', '=', 'custom_records.object_type_id')->where('tenant_id', ModelStub::ulid('query-shape-tenant')), false],
]);

it('reads an unqualified soft delete condition only on a query of the table alone', function (): void {
    $alone = CustomRecord::query()->withoutGlobalScopes()->whereNull('deleted_at');
    $joined = CustomRecord::query()->withoutGlobalScopes()->join('object_types', 'object_types.id', '=', 'custom_records.object_type_id')->whereNull('deleted_at');

    expect(QueryShape::of($alone)->hidesSoftDeleted('custom_records'))->toBeTrue()
        ->and(QueryShape::of($joined)->hidesSoftDeleted('custom_records'))->toBeFalse();
});
