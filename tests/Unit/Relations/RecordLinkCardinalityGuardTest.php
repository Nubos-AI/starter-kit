<?php

declare(strict_types=1);

use App\Enums\Engine\RelationCardinality;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordLinkCardinalityGuard;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    /** @var callable(RelationCardinality):RelationshipType */
    $this->relationshipType = fn (RelationCardinality $cardinality): RelationshipType => ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('carrier'),
        'tenant_id' => $this->tenant->getKey(),
        'from_object_type_id' => (string) $this->objectType->getKey(),
        'to_object_type_id' => (string) $this->objectType->getKey(),
        'cardinality' => $cardinality->value,
    ]);

    $this->endpoints = Mockery::mock(RecordEndpointResolver::class);
    $this->guard = new RecordLinkCardinalityGuard($this->endpoints);

    /** @var callable(callable):array<string, list<string>> */
    $this->errorsOf = function (callable $call): array {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('rejects an edge that already exists', function (): void {
    $this->endpoints->shouldNotReceive('find');

    $errors = ($this->errorsOf)(fn () => $this->guard->assert(
        ($this->relationshipType)(RelationCardinality::OneToMany),
        true,
        null,
    ));

    expect($errors['to_record_id'][0])->toBe(__('i18n.backend.actions.engine.link_records_action.this_relationship_already_exists'));
});

it('lets a many-to-many target be linked although it already hangs on another source', function (): void {
    $this->endpoints->shouldNotReceive('find');

    $errors = ($this->errorsOf)(fn () => $this->guard->assert(
        ($this->relationshipType)(RelationCardinality::ManyToMany),
        false,
        ModelStub::ulid('other-parent'),
    ));

    expect($errors)->toBe([]);
});

it('lets a one-to-many target be linked while it hangs on nobody', function (): void {
    $this->endpoints->shouldNotReceive('find');

    $errors = ($this->errorsOf)(fn () => $this->guard->assert(($this->relationshipType)(RelationCardinality::OneToMany), false, null));

    expect($errors)->toBe([]);
});

it('names the conflict plainly when the source holding the target is visible', function (): void {
    $parentId = ModelStub::ulid('other-parent');

    $this->endpoints->shouldReceive('find')->once()
        ->with((string) $this->objectType->getKey(), $parentId)
        ->andReturn(ModelStub::make(CustomRecord::class, [
            'id' => $parentId,
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $this->objectType->getKey(),
        ]));

    $errors = ($this->errorsOf)(fn () => $this->guard->assert(
        ($this->relationshipType)(RelationCardinality::OneToMany),
        false,
        $parentId,
    ));

    expect($errors['to_record_id'][0])->toBe(__('i18n.backend.actions.engine.link_records_action.the_target_record_is_already_linked_in_this_one'));
});

it('keeps a source the row access hides out of the refusal and answers the generic cardinality message', function (): void {
    $parentId = ModelStub::ulid('hidden-parent');

    $this->endpoints->shouldReceive('find')->once()->andReturnNull();

    $errors = ($this->errorsOf)(fn () => $this->guard->assert(
        ($this->relationshipType)(RelationCardinality::OneToMany),
        false,
        $parentId,
    ));

    expect($errors['to_record_id'][0])
        ->toBe(__('i18n.backend.actions.engine.link_records_action.the_relationship_violates_the_configured_cardinality_or_already_exists'))
        ->not->toBe(__('i18n.backend.actions.engine.link_records_action.the_target_record_is_already_linked_in_this_one'))
        ->and($errors['to_record_id'][0])->not->toContain($parentId);
});
