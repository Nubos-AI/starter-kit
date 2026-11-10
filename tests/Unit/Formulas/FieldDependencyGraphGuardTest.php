<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Engine\FieldDependencyGraphGuard;
use App\Support\Engine\ObjectTypeFieldLookup;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectTypeId = ModelStub::ulid('graph-guard-object-type');

    /** @var callable(string, FieldType, ?array<string, mixed>):FieldDefinition */
    $this->field = function (string $key, FieldType $type, ?array $config = null): FieldDefinition {
        return ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('graph-guard-field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'config' => $config,
        ]);
    };

    /** @var callable(string, string):FieldDefinition */
    $this->formulaField = fn (string $key, string $formula): FieldDefinition => ($this->field)(
        $key,
        FieldType::Computed,
        ['formula' => $formula, 'result_type' => 'number'],
    );

    /** @var callable(string, string):FieldDefinition */
    $this->rollupField = fn (string $key, string $sourceFieldKey): FieldDefinition => ($this->field)(
        $key,
        FieldType::Rollup,
        ['aggregate' => 'sum', 'source_field_key' => $sourceFieldKey],
    );

    /** @var callable(list<FieldDefinition>, FieldDefinition):void */
    $this->guard = function (array $fields, FieldDefinition $field): void {
        app()->instance(
            ObjectTypeFieldLookup::class,
            StaticObjectTypeFieldLookup::carrying($this->objectTypeId, $fields),
        );

        app(FieldDependencyGraphGuard::class)->guardAcyclic($field);
    };

    /** @var callable(list<FieldDefinition>, FieldDefinition):string */
    $this->cycleMessage = function (array $fields, FieldDefinition $field): string {
        /** @var array<string, list<string>>|null $errors */
        $errors = null;

        try {
            ($this->guard)($fields, $field);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        expect($errors)->toBeArray()
            ->and($errors)->toHaveKey('config');

        /** @var array<string, list<string>> $errors */
        $message = implode(' ', $errors['config']);

        expect($message)->toContain(
            __('i18n.backend.support.engine.field_dependency_graph_guard.the_fields_contain_circular_references'),
        );

        return $message;
    };
});

test('a formula that references its own field key is rejected as a circular reference', function (): void {
    $netto = ($this->field)('netto', FieldType::Number);
    $brutto = ($this->formulaField)('brutto', 'ROUND({brutto} * 1,19; 2)');

    expect(($this->cycleMessage)([$netto, $brutto], $brutto))->toContain('brutto');
});

test('a cycle across formula and roll-up fields is rejected and every field on the path is named', function (
    array $fieldSpecs,
    string $subjectKey,
    array $expectedFragments,
): void {
    $fields = array_map(
        fn (array $spec): FieldDefinition => $spec[0] === 'rollup'
            ? ($this->rollupField)($spec[1], $spec[2])
            : ($this->formulaField)($spec[1], $spec[2]),
        $fieldSpecs,
    );

    $subject = collect($fields)->firstOrFail(fn (FieldDefinition $field): bool => $field->key === $subjectKey);

    $message = ($this->cycleMessage)($fields, $subject);

    foreach ($expectedFragments as $fragment) {
        expect($message)->toContain($fragment);
    }
})->with([
    'two formula fields pointing at each other' => [
        [['formula', 'first_total', '{second_total} + 1'], ['formula', 'second_total', '{first_total} + 1']],
        'second_total',
        ['first_total', 'second_total', '→'],
    ],
    'a transitive cycle across three formula fields' => [
        [
            ['formula', 'first_total', '{second_total} + 1'],
            ['formula', 'second_total', '{third_total} + 1'],
            ['formula', 'third_total', '{first_total} + 1'],
        ],
        'third_total',
        ['first_total', 'second_total', 'third_total'],
    ],
    'a cycle through a roll-up field between two formula fields' => [
        [
            ['formula', 'gross_total', '{link_total} + 1'],
            ['rollup', 'link_total', 'net_total'],
            ['formula', 'net_total', '{gross_total} + 1'],
        ],
        'net_total',
        ['gross_total', 'link_total', 'net_total'],
    ],
    'a roll-up whose source leads back through a formula field' => [
        [['formula', 'derived_total', '{sum_total} + 1'], ['rollup', 'sum_total', 'derived_total']],
        'sum_total',
        ['derived_total', 'sum_total'],
    ],
]);

test('a formula field that references an acyclic roll-up field is accepted', function (): void {
    $netto = ($this->field)('netto', FieldType::Number);
    $link = ($this->rollupField)('link_total', 'netto');
    $gross = ($this->formulaField)('gross_total', '{link_total} + 1');

    ($this->guard)([$netto, $link, $gross], $gross);
})->throwsNoExceptions();

test('a sibling computed field with an unusable formula is skipped and logged instead of blocking the guard', function (
    ?array $brokenConfig,
): void {
    Log::spy();

    $netto = ($this->field)('netto', FieldType::Number);
    $broken = ($this->field)('legacy_broken', FieldType::Computed, $brokenConfig);
    $derived = ($this->formulaField)('derived', '{netto} + 1');

    ($this->guard)([$netto, $broken, $derived], $derived);

    Log::shouldHaveReceived('warning')->withArgs(function (mixed ...$arguments) use ($broken): bool {
        $logged = (string) json_encode($arguments);

        return str_contains($logged, (string) $broken->getKey()) || str_contains($logged, $broken->key);
    });
})->with([
    'a computed field without a configuration' => [null],
    'a computed field with an empty formula' => [['formula' => '', 'result_type' => 'number']],
    'a computed field with an unparsable formula' => [['formula' => '{unclosed + 1', 'result_type' => 'number']],
]);
