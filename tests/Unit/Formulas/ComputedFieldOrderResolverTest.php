<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\FieldDependency;
use App\Support\Engine\ComputedFieldOrderResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectTypeId = ModelStub::ulid('order-resolver-object-type');
    $this->resolver = new ComputedFieldOrderResolver;

    /** @var callable(string, FieldType):FieldDefinition */
    $this->field = fn (string $key, FieldType $type): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('order-resolver-field-'.$key),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => $type,
        'config' => $type === FieldType::Computed ? ['formula' => '1 + 1', 'result_type' => 'number'] : null,
    ]);

    /** @var callable(FieldDefinition, FieldDefinition, ?string):FieldDependency */
    $this->edge = fn (
        FieldDefinition $dependent,
        FieldDefinition $dependsOn,
        ?string $relationshipTypeId = null,
    ): FieldDependency => ModelStub::make(FieldDependency::class, [
        'id' => ModelStub::ulid('order-resolver-edge-'.$dependent->key.'-'.$dependsOn->key),
        'rollup_field_id' => (string) $dependent->getKey(),
        'depends_on_field_id' => (string) $dependsOn->getKey(),
        'relationship_type_id' => $relationshipTypeId,
    ]);

    /** @var callable(list<FieldDefinition>, list<FieldDependency>, list<string>):list<string> */
    $this->orderedKeys = function (array $fields, array $edges, array $changedFieldKeys = []): array {
        return array_map(
            static fn (FieldDefinition $field): string => $field->key,
            $this->resolver->orderWithin(
                $this->objectTypeId,
                new Collection($fields),
                new Collection($edges),
                $changedFieldKeys,
            ),
        );
    };
});

test('an object type without a single formula field yields an empty order and logs nothing', function (): void {
    Log::spy();

    expect(($this->orderedKeys)([($this->field)('amount', FieldType::Number)], []))->toBe([]);

    Log::shouldNotHaveReceived('warning');
});

test('a referenced field is placed before its dependant even when that contradicts alphabetical order', function (): void {
    $source = ($this->field)('m_source', FieldType::Number);
    $first = ($this->field)('z_first', FieldType::Computed);
    $last = ($this->field)('a_last', FieldType::Computed);

    $ordered = ($this->orderedKeys)(
        [$source, $last, $first],
        [($this->edge)($first, $source), ($this->edge)($last, $first)],
    );

    expect($ordered)->toBe(['z_first', 'a_last']);
});

test('a chain of formula fields is ordered from its source to its last dependant', function (): void {
    $source = ($this->field)('source', FieldType::Number);
    $one = ($this->field)('step_one', FieldType::Computed);
    $two = ($this->field)('step_two', FieldType::Computed);
    $three = ($this->field)('step_three', FieldType::Computed);

    $ordered = ($this->orderedKeys)(
        [$source, $three, $two, $one],
        [
            ($this->edge)($one, $source),
            ($this->edge)($two, $one),
            ($this->edge)($three, $two),
        ],
    );

    expect($ordered)->toBe(['step_one', 'step_two', 'step_three']);
});

test('two formula fields over the same input each appear exactly once', function (): void {
    $source = ($this->field)('source', FieldType::Number);
    $left = ($this->field)('left', FieldType::Computed);
    $right = ($this->field)('right', FieldType::Computed);

    $ordered = ($this->orderedKeys)(
        [$source, $left, $right],
        [($this->edge)($left, $source), ($this->edge)($right, $source)],
    );

    expect($ordered)->toHaveCount(2)
        ->and($ordered)->toContain('left')
        ->and($ordered)->toContain('right');
});

test('a formula field that depends on none of the changed field keys is left out', function (): void {
    $touched = ($this->field)('touched', FieldType::Number);
    $untouched = ($this->field)('untouched', FieldType::Number);
    $dependant = ($this->field)('dependant', FieldType::Computed);
    $unrelated = ($this->field)('unrelated', FieldType::Computed);

    $ordered = ($this->orderedKeys)(
        [$touched, $untouched, $dependant, $unrelated],
        [($this->edge)($dependant, $touched), ($this->edge)($unrelated, $untouched)],
        ['touched'],
    );

    expect($ordered)->toBe(['dependant']);
});

test('a transitive dependant of a changed field is recomputed as well', function (): void {
    $source = ($this->field)('source', FieldType::Number);
    $near = ($this->field)('near', FieldType::Computed);
    $far = ($this->field)('far', FieldType::Computed);

    $ordered = ($this->orderedKeys)(
        [$source, $near, $far],
        [($this->edge)($near, $source), ($this->edge)($far, $near)],
        ['source'],
    );

    expect($ordered)->toBe(['near', 'far']);
});

test('an empty set of changed field keys recomputes every formula field of the object type', function (): void {
    $touched = ($this->field)('touched', FieldType::Number);
    $untouched = ($this->field)('untouched', FieldType::Number);
    $dependant = ($this->field)('dependant', FieldType::Computed);
    $unrelated = ($this->field)('unrelated', FieldType::Computed);

    $ordered = ($this->orderedKeys)(
        [$touched, $untouched, $dependant, $unrelated],
        [($this->edge)($dependant, $touched), ($this->edge)($unrelated, $untouched)],
    );

    expect($ordered)->toHaveCount(2)
        ->and($ordered)->toContain('dependant')
        ->and($ordered)->toContain('unrelated');
});

test('a subtree roll-up edge inside a single object type stays out of the formula order', function (): void {
    $hierarchyId = ModelStub::ulid('order-resolver-hierarchy');

    $own = ($this->field)('own_amount', FieldType::Number);
    $subtreeTotal = ($this->field)('subtree_total', FieldType::Rollup);
    $rollupAmount = ($this->field)('rollup_amount', FieldType::Computed);

    Log::spy();

    $ordered = ($this->orderedKeys)(
        [$own, $subtreeTotal, $rollupAmount],
        [
            ($this->edge)($rollupAmount, $own),
            ($this->edge)($rollupAmount, $subtreeTotal),
            ($this->edge)($subtreeTotal, $rollupAmount, $hierarchyId),
        ],
    );

    expect($ordered)->toBe(['rollup_amount']);

    Log::shouldNotHaveReceived('warning');
});

test('a cyclic formula graph yields an empty order', function (): void {
    $alpha = ($this->field)('alpha', FieldType::Computed);
    $beta = ($this->field)('beta', FieldType::Computed);

    expect(($this->orderedKeys)(
        [$alpha, $beta],
        [($this->edge)($alpha, $beta), ($this->edge)($beta, $alpha)],
    ))->toBe([]);
});

test('a cyclic formula graph warns exactly once and names the object type and the cycle path', function (): void {
    $alpha = ($this->field)('alpha', FieldType::Computed);
    $beta = ($this->field)('beta', FieldType::Computed);

    Log::spy();

    ($this->orderedKeys)([$alpha, $beta], [($this->edge)($alpha, $beta), ($this->edge)($beta, $alpha)]);

    Log::shouldHaveReceived('warning')->withArgs(
        function (mixed ...$arguments): bool {
            $logged = (string) json_encode($arguments);

            return str_contains($logged, $this->objectTypeId)
                && str_contains($logged, 'alpha')
                && str_contains($logged, 'beta');
        },
    )->once();
});
