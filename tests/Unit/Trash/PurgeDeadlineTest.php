<?php

declare(strict_types=1);

use App\Activities\Trash\PurgeTrashedRecordsActivity;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Trash\PurgeDeadline;
use Carbon\CarbonImmutable;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->deadline = app(PurgeDeadline::class);
    $this->timezone = (string) config('engine.trash.purge_timezone');

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry);

    /** @var callable(?int, ?string):CustomRecord */
    $this->trashedRecord = fn (?int $retentionDays, ?string $deletedAtUtc): CustomRecord => ModelStub::make(
        CustomRecord::class,
        [
            'tenant_id' => $this->tenant->getKey(),
            'deleted_at' => $deletedAtUtc,
        ],
        ['objectType' => ModelStub::make(ObjectType::class, ['retention_days' => $retentionDays])],
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('ends the retention window with the last microsecond of the day in the purge timezone', function (): void {
    $deadline = $this->deadline->for(($this->trashedRecord)(1, '2026-08-28 16:02:00'));

    expect($deadline?->toDateString())->toBe('2026-08-29')
        ->and($deadline?->format('H:i:s'))->toBe('23:59:59')
        ->and($deadline?->getTimezone()->getName())->toBe($this->timezone);
});

it('counts the retention days from the deletion, not from the end of that day', function (): void {
    $deadline = $this->deadline->for(($this->trashedRecord)(14, '2026-08-28 16:02:00'));

    expect($deadline?->toDateString())->toBe('2026-09-11');
});

it('shifts the deadline by a day when the deletion falls late enough to cross into the next local day', function (): void {
    $sameDay = $this->deadline->for(($this->trashedRecord)(1, '2026-08-28 12:00:00'));
    $afterMidnightLocally = $this->deadline->for(($this->trashedRecord)(1, '2026-08-28 23:30:00'));

    expect($sameDay?->toDateString())->toBe('2026-08-29')
        ->and($afterMidnightLocally?->toDateString())->toBe('2026-08-30');
});

it('never sets a deadline without a retention period or without a deletion', function (): void {
    expect($this->deadline->for(($this->trashedRecord)(null, '2026-08-28 16:02:00')))->toBeNull()
        ->and($this->deadline->for(($this->trashedRecord)(14, null)))->toBeNull();
});

it('prefers an explicitly passed object type over the one the record carries', function (): void {
    $record = ($this->trashedRecord)(1, '2026-08-28 16:02:00');
    $longer = ModelStub::make(ObjectType::class, ['retention_days' => 30]);

    expect($this->deadline->for($record, $longer)?->toDateString())->toBe('2026-09-27');
});

it('reports the purge timezone the nightly run fires in', function (): void {
    expect($this->deadline->timezone())->toBe($this->timezone)
        ->and((string) config('engine.trash.purge_cron'))->toBe('30 0 * * *');
});

it('selects only trashed records of an object type that declares a retention period', function (): void {
    $shape = QueryShape::of(app(PurgeTrashedRecordsActivity::class)->expiredRecordsQuery([]));

    expect($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('inner join "object_types" on "object_types"."id" = "custom_records"."object_type_id"')
        ->and($shape->sql)->toContain('"object_types"."retention_days" is not null')
        ->and($shape->sql)->toContain('"custom_records"."deleted_at" is not null')
        ->and($shape->sql)->toContain('order by "custom_records"."deleted_at" asc')
        ->and($shape->hasBinding($this->timezone))->toBeTrue();
});

it('computes the deadline in the database the same way the deadline helper does', function (): void {
    $shape = QueryShape::of(app(PurgeTrashedRecordsActivity::class)->expiredRecordsQuery([]));

    expect($shape->sql)->toContain("date_trunc('day', (custom_records.deleted_at AT TIME ZONE 'UTC' AT TIME ZONE ?)")
        ->and($shape->sql)->toContain('make_interval(days => object_types.retention_days)')
        ->and($shape->sql)->toContain("interval '1 day' - interval '1 microsecond' <=")
        ->and($shape->hasBinding(CarbonImmutable::now($this->timezone)->toDateTimeString()))->toBeTrue();
});

it('leaves the trashed records of a tenant under maintenance untouched', function (): void {
    $lockedTenantId = ModelStub::ulid('locked-tenant');

    $locked = QueryShape::of(app(PurgeTrashedRecordsActivity::class)->expiredRecordsQuery([$lockedTenantId]));
    $free = QueryShape::of(app(PurgeTrashedRecordsActivity::class)->expiredRecordsQuery([]));

    expect($locked->sql)->toContain('"custom_records"."tenant_id" not in (?)')
        ->and($locked->hasBinding($lockedTenantId))->toBeTrue()
        ->and($free->sql)->not->toContain('tenant_id');
});

it('reads the trash across every tenant, unbound by the tenant scope', function (): void {
    $shape = QueryShape::of(app(PurgeTrashedRecordsActivity::class)->expiredRecordsQuery([]));

    expect($shape->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeFalse();
});
