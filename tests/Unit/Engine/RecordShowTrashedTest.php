<?php

declare(strict_types=1);

use App\Http\Middleware\Authorization\EnforceRecordAccessRules;
use App\Http\Middleware\ResolveTenantContext;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\RecordRouteResolver;
use App\Support\Trash\PurgeDeadline;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->purgeDeadline = new PurgeDeadline;

    /** @var callable(?int):ObjectType */
    $this->objectType = fn (?int $retentionDays): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'retention_days' => $retentionDays,
    ]);

    /** @var callable(?string):CustomRecord */
    $this->record = fn (?string $deletedAt): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('companies'),
        'deleted_at' => $deletedAt,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks a deleted record up including the bin and still inside the tenant', function (): void {
    $key = ModelStub::ulid('company-record');

    $attempt = QueryShape::attemptedBy(static fn (): CustomRecord => (new RecordRouteResolver)->resolve($key, withTrashed: true));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->isKeyedTo('custom_records', $key))->toBeTrue()
        ->and($attempt?->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hidesSoftDeleted('custom_records'))->toBeFalse();
});

it('hides a deleted record from a lookup that did not ask for the bin', function (): void {
    $key = ModelStub::ulid('company-record');

    $attempt = QueryShape::attemptedBy(static fn (): CustomRecord => (new RecordRouteResolver)->resolve($key));

    expect($attempt?->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('finds a record by its business key rather than by its identifier', function (): void {
    $attempt = QueryShape::attemptedBy(static fn (): CustomRecord => (new RecordRouteResolver)->resolve('CO-0000000042'));

    expect($attempt?->hasColumnCondition('custom_records', 'record_number'))->toBeFalse()
        ->and($attempt?->sql)->toContain('"record_number" = ?')
        ->and($attempt?->hasBinding('CO-0000000042'))->toBeTrue();
});

it('accepts both an identifier and a business key as a record route segment', function (): void {
    expect(RecordRouteResolver::routePattern())
        ->toBe(RecordRouteResolver::$ulidPattern.'|'.RecordRouteResolver::$businessKeyPattern);
});

it('announces the purge deadline of a deleted record in the purge timezone', function (): void {
    config()->set('engine.trash.purge_timezone', 'Europe/Berlin');

    $lateAtNight = $this->purgeDeadline->for(($this->record)('2026-01-01 23:30:00'), ($this->objectType)(14));
    $sameMorning = $this->purgeDeadline->for(($this->record)('2026-01-01 08:00:00'), ($this->objectType)(14));

    expect($lateAtNight?->toDateString())->toBe('2026-01-16')
        ->and($sameMorning?->toDateString())->toBe('2026-01-15')
        ->and($this->purgeDeadline->timezone())->toBe('Europe/Berlin');
});

it('announces no purge deadline for a deleted record without a retention period', function (): void {
    expect($this->purgeDeadline->for(($this->record)('2026-01-01 12:00:00'), ($this->objectType)(null)))
        ->toBeNull();
});

it('announces no purge deadline for a record that is not in the bin', function (): void {
    expect($this->purgeDeadline->for(($this->record)(null), ($this->objectType)(14)))->toBeNull();
});

it('resolves the tenant and the row access rules before it binds a record route', function (): void {
    $route = RouteShape::named('engine.records.show');

    expect($route->runsBefore(ResolveTenantContext::class, SubstituteBindings::class))->toBeTrue()
        ->and($route->runsBefore(EnforceRecordAccessRules::class, SubstituteBindings::class))->toBeTrue()
        ->and($route->handledBy())->toContain('RecordsController@show');
});
