<?php

declare(strict_types=1);

use App\Actions\Engine\DeleteRelationshipTypeAction;
use App\Actions\Engine\SyncHierarchyCarrierAction;
use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Models\ObjectType;
use App\Support\Engine\RelationshipKeyGenerator;
use App\Support\Engine\RollupOwnerStarter;
use App\Traits\Engine\ValidatesRelationshipTypeInput;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'name' => 'Companies',
    ]);

    $this->carrierSync = new SyncHierarchyCarrierAction(
        Mockery::mock(RelationshipKeyGenerator::class),
        Mockery::mock(DeleteRelationshipTypeAction::class),
        Mockery::mock(RollupOwnerStarter::class),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks for the carrier among the self-referential hierarchy types of that object type alone', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->carrierSync->enable($this->objectType));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('relationship_types'))->toBeTrue()
        ->and($shape->isScopedToTenant('relationship_types', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"is_hierarchy" = ?')
        ->and($shape->sql)->toContain('"from_object_type_id" = ?')
        ->and($shape->sql)->toContain('"to_object_type_id" = ?')
        ->and($shape->sql)->toContain('order by "created_at" asc, "id" asc')
        ->and($shape->bindings)->toContain((string) $this->objectType->getKey());
});

it('writes a relationship type without a hierarchy flag and without a carrier, whatever the payload carries', function (): void {
    $writer = new class
    {
        use ValidatesRelationshipTypeInput;

        /**
         * @param  array<string, mixed>  $validated
         * @return array<string, mixed>
         */
        public function attributesFor(array $validated): array
        {
            return $this->relationshipTypeAttributes($validated);
        }
    };

    $attributes = $writer->attributesFor([
        'name' => 'Parent',
        'inverse_name' => 'Child',
        'from_object_type_id' => (string) $this->objectType->getKey(),
        'to_object_type_id' => (string) $this->objectType->getKey(),
        'cardinality' => RelationCardinality::OneToMany->value,
        'cascade_behavior' => CascadeBehavior::Nullify->value,
        'is_hierarchy' => true,
        'hierarchy_relationship_type_id' => ModelStub::ulid('carrier'),
    ]);

    expect(array_keys($attributes))->toBe([
        'name',
        'inverse_name',
        'from_object_type_id',
        'to_object_type_id',
        'cardinality',
        'cascade_behavior',
        'is_required',
    ])->and($attributes['is_required'])->toBeFalse();
});
