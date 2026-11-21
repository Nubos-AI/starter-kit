<?php

declare(strict_types=1);

use App\Models\ObjectType;
use Tests\Support\ModelStub;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    /** @var callable(array<string, mixed>):ObjectType */
    $this->objectType = static fn (array $attributes = []): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'key' => 'companies',
        'slug' => 'companies',
        'name' => 'Companies',
        ...$attributes,
    ]);
});

afterEach(function (): void {
    app()->setLocale('de');
});

it('adds the translatable labels of an object type as a nullable jsonb column and drops it again', function (): void {
    $path = 'database/migrations/0001_01_01_000077_add_i18n_labels_to_object_types_table.php';

    expect(SchemaShape::ofMigration($path)->statements)->toBe(['alter table "object_types" add column "i18n_labels" jsonb null'])
        ->and(SchemaShape::ofMigration($path, 'down')->statements)->toBe(['alter table "object_types" drop column "i18n_labels"']);
});

it('shows the label of the active locale instead of the technical name', function (): void {
    app()->setLocale('de');

    expect(($this->objectType)(['i18n_labels' => ['de' => 'Firmen']])->label)->toBe('Firmen');
});

it('falls back to the technical name while neither the active nor the fallback locale carries a label', function (): void {
    app()->setLocale('en');
    config(['app.fallback_locale' => 'en']);

    expect(($this->objectType)(['i18n_labels' => ['de' => 'Firmen']])->label)->toBe('Companies')
        ->and(($this->objectType)(['i18n_labels' => null])->label)->toBe('Companies')
        ->and(($this->objectType)(['i18n_labels' => []])->label)->toBe('Companies');
});

it('takes the fallback locale before the technical name when the active locale carries no label', function (): void {
    app()->setLocale('fr');
    config(['app.fallback_locale' => 'en']);

    expect(($this->objectType)(['i18n_labels' => ['de' => 'Firmen', 'en' => 'Accounts']])->label)->toBe('Accounts');
});

it('ignores an empty label and shows the technical name', function (): void {
    app()->setLocale('de');

    expect(($this->objectType)(['i18n_labels' => ['de' => '']])->label)->toBe('Companies')
        ->and(($this->objectType)(['i18n_labels' => ['de' => '   ']])->label)->toBe('Companies');
});

it('answers an empty label for an object type that carries neither a label nor a name yet', function (): void {
    expect((new ObjectType)->label)->toBe('');
});
