<?php

declare(strict_types=1);

use App\Console\Commands\PruneAuditEntries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->command = app(PruneAuditEntries::class);
    $this->cutoff = CarbonImmutable::parse('2025-09-23 00:00:00', 'UTC');
    $this->lockedTenantId = ModelStub::ulid('locked-tenant');
});

it('deletes only the rows that fell out of the retention window, in bounded batches', function (): void {
    $shape = QueryShape::of($this->command->expiredRows($this->cutoff, 1000, []));

    expect($shape->targets('audit_entries'))->toBeTrue()
        ->and($shape->sql)->toContain('"changed_at" < ?')
        ->and($shape->sql)->toContain('limit 1000')
        ->and($shape->bindings)->toContainEqual($this->cutoff);
});

it('spares every row of a tenant that is under maintenance', function (): void {
    $shape = QueryShape::of($this->command->expiredRows($this->cutoff, 1000, [$this->lockedTenantId]));

    expect($shape->sql)->toContain('"tenant_id" not in (?)')
        ->and($shape->hasBinding($this->lockedTenantId))->toBeTrue();
});

it('leaves the tenant condition out entirely while no tenant is under maintenance', function (): void {
    expect(QueryShape::of($this->command->expiredRows($this->cutoff, 1000, []))->sql)->not->toContain('tenant_id');
});

it('honours the requested chunk size instead of the default', function (): void {
    expect(QueryShape::of($this->command->expiredRows($this->cutoff, 2, []))->sql)->toContain('limit 2');
});

it('asks which tenants are under maintenance before it touches a single audit row', function (): void {
    $shape = QueryShape::attemptedBy(static fn (): mixed => Artisan::call('audit:prune', ['--months' => 12]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('maintenance_locks'))->toBeTrue()
        ->and($shape->targets('audit_entries'))->toBeFalse();
});
