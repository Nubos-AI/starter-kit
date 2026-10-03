<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    Config::set('database.connections.pgsql.host', '127.0.0.1');
    Config::set('database.connections.pgsql.port', 1);
    DB::purge('pgsql');
});

it('binds the tenant into the query without reaching the database', function (): void {
    $tenant = new Tenant;
    $tenant->forceFill(['id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']);

    app()->instance('current_tenant', $tenant);

    $query = CustomRecord::query();

    expect($query->toSql())->toContain('"custom_records"."tenant_id" = ?')
        ->and($query->getBindings())->toContain('01JZZZZZZZZZZZZZZZZZZZZZZZ');
});

it('blocks every row when no tenant is bound', function (): void {
    app()->forgetInstance('current_tenant');

    expect(CustomRecord::query()->toSql())->toContain('1 = 0');
});

it('proves the database is unreachable in this test', function (): void {
    DB::connection('pgsql')->select('select 1');
})->throws(QueryException::class);
