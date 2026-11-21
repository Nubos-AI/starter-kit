<?php

declare(strict_types=1);

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->fieldAttributesNeverSnapshotted = ['tenant_id', 'object_type_id'];
    $this->objectTypeAttributesNeverSnapshotted = ['tenant_id'];
    $this->objectTypeKeysNeverRestorable = ['key', 'slug', 'is_system', 'hierarchy_relationship_type_id'];
});

it('snapshots every fillable field attribute except the tenant and the owning object type', function (): void {
    $expected = array_values(array_diff(
        (new FieldDefinition)->getFillable(),
        $this->fieldAttributesNeverSnapshotted,
    ));

    expect($expected)->not->toBeEmpty()
        ->and(array_intersect($expected, $this->fieldAttributesNeverSnapshotted))->toBe([])
        ->and(config('engine.definition_snapshot.field_keys'))->toBeArray()
        ->toEqualCanonicalizing($expected);
});

it('snapshots every fillable object type attribute except the tenant', function (): void {
    $expected = array_values(array_diff(
        (new ObjectType)->getFillable(),
        $this->objectTypeAttributesNeverSnapshotted,
    ));

    expect($expected)->not->toBeEmpty()
        ->and(array_intersect($expected, $this->objectTypeAttributesNeverSnapshotted))->toBe([])
        ->and(config('engine.definition_snapshot.object_type_keys'))->toBeArray()
        ->toEqualCanonicalizing($expected);
});

it('splits the object type snapshot keys into restorable and never restorable without overlap', function (): void {
    $keys = config('engine.definition_snapshot.object_type_keys');
    $restorable = config('engine.definition_snapshot.object_type_restorable');
    $never = config('engine.definition_snapshot.object_type_never_restorable');

    expect($keys)->toBeArray()->not->toBeEmpty()
        ->and($restorable)->toBeArray()->not->toBeEmpty()
        ->and($never)->toBeArray()->toEqualCanonicalizing($this->objectTypeKeysNeverRestorable);

    /** @var array<int, mixed> $keys */
    /** @var array<int, mixed> $restorable */
    /** @var array<int, mixed> $never */
    expect(array_merge($restorable, $never))->toEqualCanonicalizing($keys)
        ->and(array_values(array_intersect($restorable, $never)))->toBe([]);
});

it('carries a model default for the restorable flag a not reloaded object type would otherwise snapshot as null', function (): void {
    expect((new ObjectType)->getAttributes())->toHaveKey('requires_deletion_reason')
        ->and((new ObjectType)->requires_deletion_reason)->toBeFalse()
        ->and(config('engine.definition_snapshot.object_type_restorable'))
        ->toContain('requires_deletion_reason');
});
