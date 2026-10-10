<?php

declare(strict_types=1);

use App\DTOs\Formulas\BooleanLiteralNode;
use App\DTOs\Formulas\FormulaTypeIssue;
use App\DTOs\Formulas\FunctionCallNode;
use App\DTOs\Formulas\NumberLiteralNode;
use App\Enums\CustomFields\FieldType;
use App\Enums\Formulas\FormulaFunction;
use App\Enums\Formulas\FormulaTypeIssueCause;
use App\Enums\Formulas\FormulaValueType;
use App\Exceptions\Formulas\FormulaSyntaxException;
use App\Models\FieldDefinition;
use App\Support\Formulas\FormulaParser;
use App\Support\Formulas\FormulaTypeChecker;
use Tests\Support\Doubles\StaticFormulaFieldTypeMapper;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->objectTypeId = ModelStub::ulid('object-type');
    $this->otherObjectTypeId = ModelStub::ulid('other-object-type');

    /** @var callable(string, FieldType, ?array<string, mixed>, bool):FieldDefinition */
    $this->field = function (
        string $key,
        FieldType $type,
        ?array $config = null,
        bool $isEncrypted = false,
    ): FieldDefinition {
        return ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('field-'.$key),
            'object_type_id' => $this->objectTypeId,
            'key' => $key,
            'field_type' => $type,
            'is_encrypted' => $isEncrypted,
            'config' => $config,
        ]);
    };

    $mapper = StaticFormulaFieldTypeMapper::carrying($this->objectTypeId, [
        ($this->field)('amount', FieldType::Number),
        ($this->field)('record_number', FieldType::TextShort),
        ($this->field)('text_field', FieldType::TextShort),
        ($this->field)('start_date', FieldType::Date),
        ($this->field)('end_date', FieldType::DateTime),
        ($this->field)('unsupported_field', FieldType::MultiSelect),
        ($this->field)('rollup_field', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'amount']),
        ($this->field)('computed_text', FieldType::Computed, ['result_type' => 'text']),
        ($this->field)('self_total', FieldType::Computed, ['result_type' => 'number']),
        ($this->field)('computed_missing', FieldType::Computed),
        ($this->field)('computed_bogus', FieldType::Computed, ['result_type' => 'bogus']),
        ($this->field)('encrypted_field', FieldType::TextShort, null, true),
    ]);

    $mapper->withFields($this->otherObjectTypeId, [
        ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('field-other-only'),
            'object_type_id' => $this->otherObjectTypeId,
            'key' => 'other_only',
            'field_type' => FieldType::Number,
            'is_encrypted' => false,
        ]),
    ]);

    $this->checker = new FormulaTypeChecker($mapper);

    /** @var callable(string, FormulaValueType):list<FormulaTypeIssue> */
    $this->check = function (string $formula, FormulaValueType $resultType): array {
        return $this->checker->check(
            app(FormulaParser::class)->parse($formula),
            $this->objectTypeId,
            $resultType,
        );
    };

    /** @var callable(string, FormulaValueType):FormulaTypeIssue */
    $this->onlyIssue = function (string $formula, FormulaValueType $resultType): FormulaTypeIssue {
        $issues = ($this->check)($formula, $resultType);

        expect($issues)->toHaveCount(1);

        return $issues[0];
    };
});

test('a well typed formula produces no issue for its configured result type', function (string $formula, FormulaValueType $resultType): void {
    expect(($this->check)($formula, $resultType))->toBe([]);
})->with([
    'an arithmetic operation on a number field' => ['{amount} * 0,19', FormulaValueType::Number],
    'a concatenation of a literal and a short text field' => ['CONCAT("No. "; {record_number})', FormulaValueType::Text],
    'a comparison that yields a boolean' => ['{amount} > 1000', FormulaValueType::Boolean],
    'a nullary function that yields a date' => ['TODAY()', FormulaValueType::Date],
    'a three argument date difference' => ['DATEDIF({start_date}; {end_date}; "days")', FormulaValueType::Number],
    'a conditional whose branches share one type' => ['IF({amount} > 100; "high"; "low")', FormulaValueType::Text],
    'a number widened to text inside a concatenation' => ['CONCAT("Amount: "; {amount})', FormulaValueType::Text],
    'a division by zero that only the runtime can fail on' => ['1 / 0', FormulaValueType::Number],
    'a reference to another computed field with a configured result type' => ['{computed_text}', FormulaValueType::Text],
    'a reference to a rollup field that carries no result type' => ['{rollup_field} + 1', FormulaValueType::Number],
    'a unary minus applied to a number field' => ['-{amount}', FormulaValueType::Number],
    'an ampersand concatenation of two text fields' => ['{text_field} & {record_number}', FormulaValueType::Text],
    'an ampersand concatenation that widens a number' => ['{text_field} & {amount}', FormulaValueType::Text],
    'a trimmed concatenation' => ['TRIM({text_field} & " " & {record_number})', FormulaValueType::Text],
    'a computed field referencing its own key' => ['{self_total} + 1', FormulaValueType::Number],
]);

test('an inferred result type that contradicts the configured one is reported at the root node', function (): void {
    $issue = ($this->onlyIssue)('{amount} * 0,19', FormulaValueType::Text);

    expect($issue->cause)->toBe(FormulaTypeIssueCause::ResultTypeMismatch)
        ->and($issue->position)->toBe(0)
        ->and($issue->expectedType)->toBe(FormulaValueType::Text)
        ->and($issue->actualType)->toBe(FormulaValueType::Number);
});

test('two conditional branches of different types are reported at the else branch', function (): void {
    $issue = ($this->onlyIssue)('IF({amount} > 1; "text"; 5)', FormulaValueType::Text);

    expect($issue->cause)->toBe(FormulaTypeIssueCause::BranchTypeMismatch)
        ->and($issue->position)->toBe(25)
        ->and($issue->expectedType)->toBe(FormulaValueType::Text)
        ->and($issue->actualType)->toBe(FormulaValueType::Number);
});

test('an operand of the wrong type is reported at the violating operand', function (
    string $formula,
    FormulaValueType $resultType,
    int $position,
    FormulaValueType $expectedType,
    FormulaValueType $actualType,
): void {
    $issue = ($this->onlyIssue)($formula, $resultType);

    expect($issue->cause)->toBe(FormulaTypeIssueCause::OperandTypeMismatch)
        ->and($issue->position)->toBe($position)
        ->and($issue->expectedType)->toBe($expectedType)
        ->and($issue->actualType)->toBe($actualType);
})->with([
    'a multiplication with a text field' => [
        '{text_field} * 2',
        FormulaValueType::Number,
        0,
        FormulaValueType::Number,
        FormulaValueType::Text,
    ],
    'a comparison between a number and a text field' => [
        '{amount} > {text_field}',
        FormulaValueType::Boolean,
        11,
        FormulaValueType::Number,
        FormulaValueType::Text,
    ],
    'a unary minus applied to a text field' => [
        '-{text_field}',
        FormulaValueType::Number,
        1,
        FormulaValueType::Number,
        FormulaValueType::Text,
    ],
    'an ampersand concatenation with a date operand' => [
        '{text_field} & {start_date}',
        FormulaValueType::Text,
        15,
        FormulaValueType::Text,
        FormulaValueType::Date,
    ],
]);

test('an argument that violates its signature is reported at the argument node', function (
    string $formula,
    FormulaValueType $resultType,
    FormulaFunction $function,
    int $position,
    FormulaValueType $expectedType,
    FormulaValueType $actualType,
): void {
    $issue = ($this->onlyIssue)($formula, $resultType);

    expect($issue->cause)->toBe(FormulaTypeIssueCause::ArgumentTypeMismatch)
        ->and($issue->function)->toBe($function)
        ->and($issue->position)->toBe($position)
        ->and($issue->expectedType)->toBe($expectedType)
        ->and($issue->actualType)->toBe($actualType);
})->with([
    'a text literal where the first argument must be a number' => [
        'ROUND("abc"; 2)',
        FormulaValueType::Number,
        FormulaFunction::Round,
        6,
        FormulaValueType::Number,
        FormulaValueType::Text,
    ],
    'a number field where the first argument must be a date' => [
        'DATEDIF({amount}; {end_date}; "days")',
        FormulaValueType::Number,
        FormulaFunction::DateDif,
        8,
        FormulaValueType::Date,
        FormulaValueType::Number,
    ],
    'a number where a text argument outside a concatenation is required' => [
        'DATEDIF({start_date}; {end_date}; 5)',
        FormulaValueType::Number,
        FormulaFunction::DateDif,
        34,
        FormulaValueType::Text,
        FormulaValueType::Number,
    ],
    'a text literal where the second argument must be a number' => [
        'ROUND({amount}; "2")',
        FormulaValueType::Number,
        FormulaFunction::Round,
        16,
        FormulaValueType::Number,
        FormulaValueType::Text,
    ],
    'a non boolean condition in a conditional' => [
        'IF({amount}; 1; 2)',
        FormulaValueType::Number,
        FormulaFunction::IfThenElse,
        3,
        FormulaValueType::Boolean,
        FormulaValueType::Number,
    ],
]);

test('a field reference that cannot be used is reported with its key and position', function (
    string $formula,
    FormulaValueType $resultType,
    string $cause,
    string $fieldKey,
    int $position,
): void {
    $issue = ($this->onlyIssue)($formula, $resultType);

    expect($issue->cause)->toBe(FormulaTypeIssueCause::from($cause))
        ->and($issue->fieldKey)->toBe($fieldKey)
        ->and($issue->position)->toBe($position);
})->with([
    'an unknown field key' => [
        '{does_not_exist} + 1',
        FormulaValueType::Number,
        'unknown_field_reference',
        'does_not_exist',
        0,
    ],
    'a field that belongs to another object type' => [
        '{other_only} + 1',
        FormulaValueType::Number,
        'unknown_field_reference',
        'other_only',
        0,
    ],
    'an encrypted field' => [
        'CONCAT("Key: "; {encrypted_field})',
        FormulaValueType::Text,
        'encrypted_field_reference',
        'encrypted_field',
        16,
    ],
    'a field type that has no formula value type' => [
        'SUM({unsupported_field}; 1)',
        FormulaValueType::Number,
        'unsupported_field_type',
        'unsupported_field',
        4,
    ],
    'a computed field without a configuration' => [
        '{computed_missing} + 1',
        FormulaValueType::Number,
        'unconfigured_result_type',
        'computed_missing',
        0,
    ],
    'a computed field with an unusable result type' => [
        '1 + {computed_bogus}',
        FormulaValueType::Number,
        'unconfigured_result_type',
        'computed_bogus',
        4,
    ],
]);

test('an unresolvable field inside a call suppresses every follow-up issue', function (): void {
    $issues = ($this->check)('ROUND({does_not_exist}; 2)', FormulaValueType::Number);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]->cause)->toBe(FormulaTypeIssueCause::UnknownFieldReference)
        ->and($issues[0]->fieldKey)->toBe('does_not_exist')
        ->and($issues[0]->position)->toBe(6);
});

test('the two argument form of a date difference never reaches the type checker', function (): void {
    expect(function (): void {
        app(FormulaParser::class)->parse('DATEDIF({start_date}; {end_date})');
    })->toThrow(FormulaSyntaxException::class);

    expect(($this->check)('DATEDIF({start_date}; {end_date}; "days")', FormulaValueType::Number))->toBe([]);
});

test('a hand built call with a broken arity never throws', function (): void {
    $tooFewArgumentsForRound = new FunctionCallNode(
        FormulaFunction::Round,
        [new NumberLiteralNode('1', 6)],
        0,
    );

    $tooManyArgumentsForRound = new FunctionCallNode(
        FormulaFunction::Round,
        [new NumberLiteralNode('1', 6), new NumberLiteralNode('2', 9), new NumberLiteralNode('3', 12)],
        0,
    );

    $tooFewArgumentsForConditional = new FunctionCallNode(
        FormulaFunction::IfThenElse,
        [new BooleanLiteralNode(true, 3), new NumberLiteralNode('1', 9)],
        0,
    );

    expect($this->checker->check($tooFewArgumentsForRound, $this->objectTypeId, FormulaValueType::Number))->toBe([])
        ->and($this->checker->check($tooManyArgumentsForRound, $this->objectTypeId, FormulaValueType::Number))->toBe([])
        ->and($this->checker->check($tooFewArgumentsForConditional, $this->objectTypeId, FormulaValueType::Number))->toBe([]);
});

test('an issue renders every detail it carries', function (): void {
    $issue = new FormulaTypeIssue(
        cause: FormulaTypeIssueCause::ArgumentTypeMismatch,
        position: 8,
        fieldKey: 'amount',
        function: FormulaFunction::DateDif,
        expectedType: FormulaValueType::Date,
        actualType: FormulaValueType::Number,
    );

    expect($issue->message())->toBe(
        'Ein Argument passt nicht zur Funktionssignatur an Stelle 8'
        .' (Feld „amount“, Funktion „DATEDIF“, erwartet date, erhalten number).',
    );
});

test('an issue without details renders no parenthesis', function (): void {
    $issue = new FormulaTypeIssue(
        cause: FormulaTypeIssueCause::UnknownFieldReference,
        position: 0,
    );

    expect($issue->message())->toBe('Das referenzierte Feld existiert auf diesem Objekttyp nicht an Stelle 0.');
});

test('every issue cause carries a distinct non-empty label', function (): void {
    expect(FormulaTypeIssueCause::cases())->toHaveCount(8);

    $labels = [];

    foreach (FormulaTypeIssueCause::cases() as $cause) {
        $label = $cause->label();

        expect($label)->toBeString()
            ->and(trim($label))->not->toBe('');

        $labels[] = $label;
    }

    expect($labels)->toHaveCount(count(array_unique($labels)));
});
