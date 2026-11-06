<?php

declare(strict_types=1);

use App\Actions\Promotion\ExecutePromotionRunAction;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\TargetKeyResolver;
use Illuminate\Support\Str;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('bundle-scope-tenant');
    $this->objectTypeId = ModelStub::ulid('bundle-scope-type');

    $this->objectTypeRow = fn (string $tenantId): array => [
        'id' => $this->objectTypeId,
        'tenant_id' => $tenantId,
        'hierarchy_relationship_type_id' => null,
        'key' => 'companies',
        'slug' => 'companies',
        'business_key_prefix' => 'CO',
        'is_system' => false,
        'requires_deletion_reason' => false,
        'is_navigable' => true,
        'storage_strategy' => 'generic',
        'name' => 'Firmen',
        'nav_icon' => 'database',
        'nav_position' => 0,
        'record_number_format' => null,
        'retention_days' => null,
        'dedup_keys' => null,
    ];

    /** @var callable(Closure():mixed, string):array<string, QueryShape> */
    $this->queriesOf = function (Closure $entry, string $ownerTenantId): array {
        $connection = StaticQueryConnection::install(fn (string $sql): array => match (true) {
            str_contains($sql, 'from "object_types"') => [($this->objectTypeRow)($ownerTenantId)],
            str_contains($sql, 'from "tenants"') => [['id' => $ownerTenantId, 'name' => 'Export', 'slug' => 'export']],
            default => [],
        });

        try {
            $entry();
        } finally {
            StaticQueryConnection::uninstall();
        }

        $shapes = [];

        foreach ($connection->queries as $query) {
            $table = Str::betweenFirst($query['sql'], 'from "', '"');
            $shapes[$table] ??= new QueryShape($query['sql'], $query['bindings']);
        }

        return $shapes;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads the aging rules of an export across the object type tenant scope', function (): void {
    $shape = ($this->queriesOf)(fn (): mixed => app(ConfigBundleSerializer::class)->serialize('the-exported-tenant'), 'the-exported-tenant')['aging_rules'];

    expect($shape->sql)->not->toContain('exists (select * from "object_types"')
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue();
});

it('reads a tenant bound artifact of an export across the tenant scope', function (): void {
    $shape = ($this->queriesOf)(fn (): mixed => app(ConfigBundleSerializer::class)->serialize('the-exported-tenant'), 'the-exported-tenant')['object_types'];

    expect($shape->isScopedToTenant('object_types', 'the-exported-tenant'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('reads the aging rules of an import target across the object type tenant scope', function (): void {
    $tenantId = (string) $this->tenant->getKey();
    $shape = ($this->queriesOf)(fn (): mixed => app(TargetKeyResolver::class)->idFor(ArtifactKind::AgingRules, 'companies:stale'), $tenantId)['aging_rules'];

    expect($shape->sql)->not->toContain('exists (select * from "object_types"')
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue();
});

it('reads a tenant bound artifact of an import target for the addressed tenant alone', function (): void {
    $tenantId = (string) $this->tenant->getKey();
    $shape = ($this->queriesOf)(fn (): mixed => app(TargetKeyResolver::class)->idFor(ArtifactKind::ObjectTypes, 'companies'), $tenantId)['object_types'];

    expect($shape->isScopedToTenant('object_types', $tenantId))->toBeTrue()
        ->and(substr_count($shape->sql, 'tenant_id'))->toBe(1);
});

it('snapshots a promoted aging rule across both scopes so the row is found in the target tenant', function (): void {
    $rowOf = new ReflectionMethod(ExecutePromotionRunAction::class, 'rowOf');

    $shape = QueryShape::attemptedBy(fn (): mixed => $rowOf->invoke(
        app(ExecutePromotionRunAction::class),
        ArtifactKind::AgingRules,
        ModelStub::ulid('promoted-aging-rule'),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape?->targets('aging_rules'))->toBeTrue()
        ->and($shape?->sql)->not->toContain('exists (select * from "object_types"')
        ->and($shape?->hasBinding(ModelStub::ulid('promoted-aging-rule')))->toBeTrue();
});

it('snapshots a promoted tenant bound artifact across the tenant scope', function (): void {
    $rowOf = new ReflectionMethod(ExecutePromotionRunAction::class, 'rowOf');

    $shape = QueryShape::attemptedBy(fn (): mixed => $rowOf->invoke(
        app(ExecutePromotionRunAction::class),
        ArtifactKind::ObjectTypes,
        ModelStub::ulid('promoted-object-type'),
    ));

    expect($shape?->targets('object_types'))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});
