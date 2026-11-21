<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('speaks german out of the box', function (): void {
    expect(app()->getLocale())->toBe('de')
        ->and(config('app.locale'))->toBe('de');
});

it('renders the frameworks own validation messages in german', function (): void {
    $errors = Validator::make(
        ['name' => null, 'email' => 'not-an-address'],
        ['name' => ['required'], 'email' => ['required', 'email']],
    )->errors();

    expect($errors->first('name'))->toBe('Name muss ausgefüllt werden.')
        ->and($errors->first('email'))->toBe('E-Mail-Adresse muss eine gültige E-Mail-Adresse sein.');
});

it('names the business key fields of an object type by the labels its form shows', function (): void {
    $errors = Validator::make(
        ['business_key_prefix' => null, 'record_number_format' => 'abc'],
        ['business_key_prefix' => ['required'], 'record_number_format' => ['regex:/^#$/']],
    )->errors();

    expect($errors->first('business_key_prefix'))->toBe('Kürzel muss ausgefüllt werden.')
        ->and($errors->first('record_number_format'))->toStartWith('Nummernkreis ');
});
