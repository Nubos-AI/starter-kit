<?php

declare(strict_types=1);

use App\Actions\Promotion\ExecutePromotionRunAction;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Models\AgingRule;
use App\Models\ObjectType;
use App\Support\ConfigBundle\ConfigBundleSerializer;
use App\Support\ConfigBundle\TargetKeyResolver;
use Illuminate\Database\Eloquent\Builder;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('bundle-scope-tenant');
    $this->objectTypeId = ModelStub::ulid('bundle-scope-type');

    $this->agingEntry = [
        'table' => 'aging_rules',
        'model' => AgingRule::class,
        'scope' => 'via',
        'via_column' => 'object_type_id',
        'kind' => 'aging-rules',
        'remap' => ['object_type_id' => 'object_types'],
    ];

    $this->objectTypeEntry = [
        'table' => 'object_types',
        'model' => ObjectType::class,
        'scope' => 'direct',
        'kind' => 'object-types',
        'remap' => [],
    ];

    $this->serializerRows = function (array $entry, Closure $filter): ?QueryShape {
        $rows = new ReflectionMethod(ConfigBundleSerializer::class, 'rows');

        return QueryShape::attemptedBy(fn (): mixed => $rows->invoke(app(ConfigBundleSerializer::class), $entry, $filter));
    };

    $this->targetRows = function (array $entry, array $rowsByTable): ?QueryShape {
        $rowsOf = new ReflectionMethod(TargetKeyResolver::class, 'rowsOf');

        return QueryShape::attemptedBy(fn (): mixed => $rowsOf->invoke(
            app(TargetKeyResolver::class),
            $entry,
            (string) $this->tenant->getKey(),
            $rowsByTable,
        ));
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads the aging rules of an export across the object type tenant scope', function (): void {
    $shape = ($this->serializerRows)(
        $this->agingEntry,
        fn (Builder $query): Builder => $query->whereIn('object_type_id', [$this->objectTypeId]),
    );

    expect($shape)->not->toBeNull()
        ->and($shape?->targets('aging_rules'))->toBeTrue()
        ->and($shape?->sql)->not->toContain('exists (select * from "object_types"')
        ->and($shape?->hasBinding($this->objectTypeId))->toBeTrue();
});

it('reads a tenant bound artifact of an export across the tenant scope', function (): void {
    $shape = ($this->serializerRows)(
        $this->objectTypeEntry,
        fn (Builder $query): Builder => $query->where('tenant_id', 'the-exported-tenant'),
    );

    expect($shape?->targets('object_types'))->toBeTrue()
        ->and($shape?->hasBinding('the-exported-tenant'))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('reads the aging rules of an import target across the object type tenant scope', function (): void {
    $shape = ($this->targetRows)($this->agingEntry, ['object_types' => [['id' => $this->objectTypeId]]]);

    expect($shape)->not->toBeNull()
        ->and($shape?->targets('aging_rules'))->toBeTrue()
        ->and($shape?->sql)->not->toContain('exists (select * from "object_types"');
});

it('reads a tenant bound artifact of an import target for the addressed tenant alone', function (): void {
    $shape = ($this->targetRows)($this->objectTypeEntry, []);

    expect($shape?->targets('object_types'))->toBeTrue()
        ->and($shape?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and(substr_count((string) $shape?->sql, 'tenant_id'))->toBe(1);
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
