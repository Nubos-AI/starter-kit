<?php

declare(strict_types=1);

use App\Models\FieldDefinition;
use App\Support\Engine\MoneyFieldConfigValidator;
use Illuminate\Validation\ValidationException;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('accepts static or record based currencies', function (array $config): void {
    expect(fn () => app(MoneyFieldConfigValidator::class)->validate(ModelStub::make(FieldDefinition::class, ['config' => $config])))
        ->not->toThrow(ValidationException::class);
})->with([
    'no currency at all' => [[]],
    'static currency' => [['currency' => 'USD']],
    'record based currency' => [['currency_field' => 'currency']],
]);

it('rejects ambiguous or malformed currency configurations on the offending key', function (array $config, array $errorKeys): void {
    expect(fn () => app(MoneyFieldConfigValidator::class)->validate(ModelStub::make(FieldDefinition::class, ['config' => $config])))
        ->toThrow(function (ValidationException $exception) use ($errorKeys): void {
            expect(array_keys($exception->errors()))->toEqualCanonicalizing($errorKeys);
        });
})->with([
    'currency that is no iso code' => [['currency' => 'euro'], ['currency']],
    'currency field that is a path' => [['currency_field' => 'nested.currency'], ['currency_field']],
    'static and record based currency together' => [['currency' => 'USD', 'currency_field' => 'currency'], ['currency', 'currency_field']],
]);
