<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Enums\Authorization\RoleScope;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Enums\Ui\NavIcon;
use App\Enums\Users\Salutation;
use App\Enums\Webhooks\WebhookEventType;
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

it('labels every case of a user facing enum instead of showing its technical key', function (string $enumClass): void {
    foreach ($enumClass::cases() as $case) {
        expect($case->label())->not->toBe('')
            ->and($case->label())->not->toBe($case->value);
    }
})->with([
    'relationship cardinalities' => RelationCardinality::class,
    'cascade behaviors' => CascadeBehavior::class,
    'field types' => FieldType::class,
    'navigation icons' => NavIcon::class,
    'role scopes' => RoleScope::class,
    'role authorities' => RoleAuthority::class,
    'webhook event types' => WebhookEventType::class,
    'salutations' => Salutation::class,
]);

it('keeps english copy out of every selectable enum label', function (string $enumClass): void {
    $english = ['Prefer not to say', 'Plain text', 'Mr.', 'Ms.', 'Mx.', 'Not set', 'Unknown', 'None'];

    foreach ($enumClass::cases() as $case) {
        expect($english)->not->toContain($case->label(), "{$enumClass}::{$case->name} is english");
    }
})->with([
    'field types' => FieldType::class,
    'relationship cardinalities' => RelationCardinality::class,
    'cascade behaviors' => CascadeBehavior::class,
    'role scopes' => RoleScope::class,
    'role authorities' => RoleAuthority::class,
    'webhook event types' => WebhookEventType::class,
    'salutations' => Salutation::class,
]);

it('names the webhook event types and the role authorities in german', function (): void {
    expect(WebhookEventType::RecordStageChanged->label())->toBe('Stage gewechselt')
        ->and(RoleAuthority::SuperAdmin->label())->toBe('Uneingeschränkte Verwaltung');
});

it('offers the salutation options in german', function (): void {
    expect(Salutation::Mister->label())->toBe('Herr')
        ->and(Salutation::Miss->label())->toBe('Frau')
        ->and(Salutation::Unknown->label())->toBe('Keine Angabe');
});

it('offers the enum labels, field type categories and descriptions in german', function (): void {
    $fieldTypeLabels = array_map(static fn (FieldType $type): string => $type->label(), FieldType::cases());

    expect(array_map(static fn (RelationCardinality $case): string => $case->label(), RelationCardinality::cases()))
        ->toBe(['Eins zu viele', 'Viele zu viele'])
        ->and(array_map(static fn (CascadeBehavior $case): string => $case->label(), CascadeBehavior::cases()))
        ->toBe([
            'Verknüpfte Datensätze mitlöschen',
            'Löschen verhindern',
            'Verknüpfung entfernen',
        ])
        ->and($fieldTypeLabels)->toContain('Dezimalzahl')
        ->and($fieldTypeLabels)->toContain('Betrag')
        ->and($fieldTypeLabels)->not->toContain('Text Short')
        ->and(FieldType::Computed->label())->toBe('Formel')
        ->and(FieldType::TextShort->label())->toBe('Kurzer Text')
        ->and(FieldType::Decimal->label())->toBe('Dezimalzahl')
        ->and(FieldType::Money->label())->toBe('Betrag')
        ->and(FieldType::Computed->category()->value)->toBe('calculated')
        ->and(FieldType::Computed->category()->label())->toBe('Berechnet')
        ->and(FieldType::Computed->description())->toContain('anderen Feldern');

    foreach (FieldType::cases() as $type) {
        expect($type->description())->not->toBe('')
            ->and($type->category()->label())->not->toBe('')
            ->and($type->category()->label())->not->toBe($type->category()->value);
    }
});
