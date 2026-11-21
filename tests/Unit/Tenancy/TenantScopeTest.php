<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('pins every record query to the bound tenant', function (): void {
    $tenant = AccessContext::tenant();

    $shape = QueryShape::of(CustomRecord::class);

    expect($shape->isScopedToTenant('custom_records', (string) $tenant->getKey()))->toBeTrue()
        ->and($shape->blocksEveryRow())->toBeFalse();
});

it('blocks every row when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(CustomRecord::class)->blocksEveryRow())->toBeTrue();
});

it('pins configuration tables to the tenant as well', function (): void {
    $tenant = AccessContext::tenant();

    expect(QueryShape::of(ObjectType::class)->isScopedToTenant('object_types', (string) $tenant->getKey()))->toBeTrue();
});

it('keeps soft deleted records out of the default query', function (): void {
    AccessContext::tenant();

    expect(QueryShape::of(CustomRecord::class)->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('drops the tenant condition only when the scope is removed on purpose', function (): void {
    $tenant = AccessContext::tenant();

    $shape = QueryShape::of(CustomRecord::withoutTenantScope());

    expect($shape->isScopedToTenant('custom_records', (string) $tenant->getKey()))->toBeFalse()
        ->and($shape->blocksEveryRow())->toBeFalse();
});

it('cannot reach a database at all in this suite', function (): void {
    DB::connection()->select('select 1');
})->throws(QueryException::class);
