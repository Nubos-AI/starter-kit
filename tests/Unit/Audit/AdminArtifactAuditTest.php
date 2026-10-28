<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\Audit\ActorResolver;
use App\Support\Audit\AdminArtifactAuditor;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->boundTenant = AccessContext::tenant('bound-tenant');
    $this->explicitTenantId = ModelStub::ulid('explicit-tenant');
    $this->role = ModelStub::make(Role::class, ['id' => ModelStub::ulid('audited-role'), 'name' => 'operator']);

    $this->auditor = new class(app(ActorResolver::class)) extends AdminArtifactAuditor
    {
        protected function nextVersion(Model $auditable): int
        {
            return 7;
        }
    };

    $this->realAuditor = app(AdminArtifactAuditor::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('files the entry under the tenant the caller names, not the one that happens to be bound', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->auditor->record(
        $this->role,
        [],
        ['note' => 'wins'],
        $this->explicitTenantId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toStartWith('insert into "audit_entries"')
        ->and($shape->hasBinding($this->explicitTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->boundTenant->getKey()))->toBeFalse();
});

it('falls back to the bound tenant when the caller names none', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->auditor->record($this->role, [], ['note' => 'stays']));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding((string) $this->boundTenant->getKey()))->toBeTrue();
});

it('carries the named tenant through the created, updated and deleted wrappers', function (): void {
    $updated = ModelStub::make(Role::class, ['id' => ModelStub::ulid('updated-role'), 'name' => 'before']);
    $updated->name = 'after';
    $updated->syncChanges();

    $calls = [
        fn (): mixed => $this->auditor->recordCreated($this->role, $this->explicitTenantId),
        fn (): mixed => $this->auditor->recordUpdated($updated, $this->explicitTenantId),
        fn (): mixed => $this->auditor->recordDeleted($this->role, $this->explicitTenantId),
    ];

    foreach ($calls as $call) {
        $shape = QueryShape::attemptedBy($call);

        expect($shape)->not->toBeNull()
            ->and($shape->hasBinding($this->explicitTenantId))->toBeTrue()
            ->and($shape->hasBinding((string) $this->boundTenant->getKey()))->toBeFalse();
    }
});

it('writes nothing at all when there is neither a bound nor a named tenant', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::attemptedBy(fn (): mixed => $this->realAuditor->record($this->role, [], ['note' => 'orphan'])))->toBeNull()
        ->and(QueryShape::attemptedBy(fn (): mixed => $this->realAuditor->recordEvent($this->role, 'operation.orphan', ['note' => 'orphan'])))->toBeNull();
});

it('ignores the surrogate key and the timestamps so a touch alone never files an entry', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->realAuditor->record(
        $this->role,
        ['id' => 'a', 'created_at' => 'then', 'updated_at' => 'then'],
        ['id' => 'b', 'created_at' => 'now', 'updated_at' => 'now'],
    ));

    expect($shape)->toBeNull();
});

it('stores an empty event payload as an empty json array rather than as nothing', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->auditor->recordEvent(
        $this->role,
        'operation.maintenance_released',
        [],
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding('operation.maintenance_released'))->toBeTrue()
        ->and($shape->hasBinding('[]'))->toBeTrue();
});

it('records an event even for a tenant identifier that no tenant row backs', function (): void {
    $unknownTenantId = ModelStub::ulid('tenant-that-never-existed');

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->auditor->recordEvent(
        $this->role,
        'operation.unknown_tenant',
        ['ok' => true],
        $unknownTenantId,
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($unknownTenantId))->toBeTrue();
});

it('looks the next version up per audited artifact, not per tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->realAuditor->record($this->role, [], ['note' => 'v']));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('audit_entries'))->toBeTrue()
        ->and($shape->sql)->toContain('max("version")')
        ->and($shape->hasBinding($this->role->getMorphClass()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->role->getKey()))->toBeTrue();
});
