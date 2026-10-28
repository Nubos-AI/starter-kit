<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
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

it('offers the relationship cardinalities in german', function (): void {
    expect(array_map(static fn (RelationCardinality $case): string => $case->label(), RelationCardinality::cases()))
        ->toBe(['Eins zu viele', 'Viele zu viele']);
});

it('offers the cascade behaviors in german', function (): void {
    expect(array_map(static fn (CascadeBehavior $case): string => $case->label(), CascadeBehavior::cases()))
        ->toBe([
            'Verknüpfte Datensätze mitlöschen',
            'Löschen verhindern',
            'Verknüpfung entfernen',
        ]);
});

it('offers every field type under a german label instead of its technical key', function (): void {
    $labels = array_map(static fn (FieldType $type): string => $type->label(), FieldType::cases());

    expect($labels)->toContain('Dezimalzahl')
        ->and($labels)->toContain('Betrag')
        ->and($labels)->not->toContain('Text Short');

    foreach (FieldType::cases() as $type) {
        expect($type->label())->not->toBe($type->value);
    }
});

it('explains the computed field type in german and files it under the calculated category', function (): void {
    expect(FieldType::Computed->label())->toBe('Formel')
        ->and(FieldType::TextShort->label())->toBe('Kurzer Text')
        ->and(FieldType::Decimal->label())->toBe('Dezimalzahl')
        ->and(FieldType::Computed->category()->value)->toBe('calculated')
        ->and(FieldType::Computed->category()->label())->toBe('Berechnet')
        ->and(FieldType::Computed->description())->toContain('anderen Feldern');
});

it('gives every field type a category label and a description the drawer can show', function (): void {
    foreach (FieldType::cases() as $type) {
        expect($type->description())->not->toBe('')
            ->and($type->category()->label())->not->toBe('')
            ->and($type->category()->label())->not->toBe($type->category()->value);
    }
});
