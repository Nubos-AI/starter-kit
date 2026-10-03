<?php

declare(strict_types=1);

use App\Contracts\Formulas\FormulaNode;
use App\DTOs\Formulas\BinaryOperationNode;
use App\Enums\CustomFields\FieldType;
use App\Exceptions\Formulas\FormulaSyntaxException;
use App\Models\FieldDefinition;
use App\Support\Formulas\FormulaFieldConfigValidator;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaTypeChecker;
use Illuminate\Validation\ValidationException;
use Tests\Support\Doubles\StaticFormulaFieldTypeMapper;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectTypeId = ModelStub::ulid('config-validator-object-type');

    /** @var callable(string, FieldType, ?array<string, mixed>, bool):FieldDefinition */
    $this->field = function (
        string $key,
        FieldType $type,
        mixed $config = null,
        bool $isEncrypted = false,
    ): FieldDefinition {
        return ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('config-validator-field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_encrypted' => $isEncrypted,
            'config' => $config,
        ]);
    };

    $mapper = StaticFormulaFieldTypeMapper::carrying($this->objectTypeId, [
        ($this->field)('netto', FieldType::Number),
        ($this->field)('steuer', FieldType::Number),
        ($this->field)('secret_note', FieldType::TextShort, null, true),
    ]);

    $this->validator = new FormulaFieldConfigValidator(
        app(FormulaParser::class),
        new FormulaTypeChecker($mapper),
    );

    /** @var callable(mixed):FieldDefinition */
    $this->computedWith = fn (mixed $config): FieldDefinition => ($this->field)('brutto', FieldType::Computed, $config);

    /** @var callable(mixed):list<string> */
    $this->rejectionOf = function (mixed $config): array {
        /** @var array<string, list<string>>|null $errors */
        $errors = null;

        try {
            $this->validator->validate(($this->computedWith)($config));
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        expect($errors)->toBeArray()
            ->and($errors)->toHaveKey('config');

        /** @var array<string, list<string>> $errors */
        $messages = $errors['config'];

        expect($messages)->not->toBeEmpty();

        return $messages;
    };
});

test('a usable configuration returns the parsed formula for the caller to build its edges from', function (): void {
    $node = $this->validator->validate(($this->computedWith)([
        'formula' => '{netto} + {steuer}',
        'result_type' => 'number',
    ]));

    expect($node)->toBeInstanceOf(FormulaNode::class)
        ->and($node)->toBeInstanceOf(BinaryOperationNode::class);
});

test('an unusable configuration is rejected under the config key', function (mixed $config): void {
    expect(($this->rejectionOf)($config))->not->toBeEmpty();
})->with([
    'a missing configuration' => [null],
    'a configuration that is not an array' => ['{netto} + 1'],
    'a configuration without a formula' => [['result_type' => 'number']],
    'a configuration with an empty formula' => [['formula' => '', 'result_type' => 'number']],
    'a configuration with a blank formula' => [['formula' => '   ', 'result_type' => 'number']],
    'a configuration whose formula is not a string' => [['formula' => 42, 'result_type' => 'number']],
    'a configuration without a result type' => [['formula' => '{netto} + 1']],
    'a configuration with an unknown result type' => [['formula' => '{netto} + 1', 'result_type' => 'currency']],
]);

test('a missing result type names every allowed value', function (): void {
    $message = implode(' ', ($this->rejectionOf)(['formula' => '{netto} + 1']));

    expect($message)->toContain('number')
        ->and($message)->toContain('text')
        ->and($message)->toContain('date')
        ->and($message)->toContain('boolean');
});

test('a syntax error is reported as a validation error that names the position', function (): void {
    $formula = '{netto} # 2';
    $position = null;

    try {
        app(FormulaParser::class)->parse($formula);
    } catch (FormulaSyntaxException $exception) {
        $position = $exception->position;
    }

    expect($position)->toBe(8);

    $message = implode(' ', ($this->rejectionOf)(['formula' => $formula, 'result_type' => 'number']));

    expect($message)->toContain('Stelle '.$position);
});

test('a formula whose type contradicts the configured result type is rejected', function (): void {
    $message = implode(' ', ($this->rejectionOf)([
        'formula' => '{netto} * 1,19',
        'result_type' => 'text',
    ]));

    expect($message)->toContain('text')
        ->and($message)->toContain('number');
});

test('every type issue of one formula is reported, not only the first', function (): void {
    $messages = ($this->rejectionOf)([
        'formula' => '{missing_one} + {missing_two}',
        'result_type' => 'number',
    ]);

    expect($messages)->toHaveCount(2)
        ->and(implode(' ', $messages))->toContain('missing_one')
        ->and(implode(' ', $messages))->toContain('missing_two');
});

test('a formula that references an encrypted field is rejected', function (): void {
    $message = implode(' ', ($this->rejectionOf)([
        'formula' => 'CONCAT("Key: "; {secret_note})',
        'result_type' => 'text',
    ]));

    expect($message)->toContain('secret_note');
});

test('a formula that references an unknown field is rejected', function (): void {
    $message = implode(' ', ($this->rejectionOf)([
        'formula' => '{does_not_exist} + 1',
        'result_type' => 'number',
    ]));

    expect($message)->toContain('does_not_exist');
});
