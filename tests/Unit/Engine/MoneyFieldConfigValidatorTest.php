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
    app(MoneyFieldConfigValidator::class)->validate(ModelStub::make(FieldDefinition::class, ['config' => $config]));
    expect(true)->toBeTrue();
})->with([[[]], [['currency' => 'USD']], [['currency_field' => 'currency']]]);

it('rejects ambiguous or malformed currency configurations', function (array $config): void {
    expect(fn () => app(MoneyFieldConfigValidator::class)->validate(ModelStub::make(FieldDefinition::class, ['config' => $config])))
        ->toThrow(ValidationException::class);
})->with([
    [['currency' => 'euro']],
    [['currency_field' => 'nested.currency']],
    [['currency' => 'USD', 'currency_field' => 'currency']],
]);
