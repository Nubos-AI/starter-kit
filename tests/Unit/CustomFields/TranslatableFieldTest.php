<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\I18n\TranslatableValueResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    AccessContext::tenant();

    $this->resolver = app(TranslatableValueResolver::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('prefers the active locale when the map carries it', function (): void {
    app()->setLocale('de');

    expect($this->resolver->resolve(['de' => 'Hallo', 'en' => 'Hi']))->toBe('Hallo');
});

it('falls back to the fallback locale when the active one is missing', function (): void {
    app()->setLocale('fr');
    config(['app.fallback_locale' => 'en']);

    expect($this->resolver->resolve(['de' => 'Hallo', 'en' => 'Hi']))->toBe('Hi');
});

it('takes the first available entry when neither locale nor fallback match', function (): void {
    app()->setLocale('fr');
    config(['app.fallback_locale' => 'es']);

    expect($this->resolver->resolve(['de' => 'Hallo']))->toBe('Hallo');
});

it('resolves an empty or missing map to null', function (): void {
    expect($this->resolver->resolve([]))->toBeNull()
        ->and($this->resolver->resolve(null))->toBeNull();
});

it('resolves the map only for a field that is marked translatable', function (): void {
    app()->setLocale('de');

    $registry = app(FieldTypeRegistry::class);
    $map = ['de' => 'Hallo', 'en' => 'Hi'];

    $translatable = ModelStub::make(FieldDefinition::class, [
        'field_type' => FieldType::TextShort->value,
        'is_translatable' => true,
    ]);
    $plain = ModelStub::make(FieldDefinition::class, [
        'field_type' => FieldType::TextShort->value,
        'is_translatable' => false,
    ]);

    expect($registry->read($map, $translatable))->toBe('Hallo')
        ->and($registry->read($map, $plain))->toBe($map);
});

it('round trips a locale value through put and resolve', function (): void {
    app()->setLocale('en');

    $map = $this->resolver->put([], 'en', 'Hello');
    $map = $this->resolver->put($map, 'de', 'Hallo');

    expect($this->resolver->resolve($map))->toBe('Hello');
});
