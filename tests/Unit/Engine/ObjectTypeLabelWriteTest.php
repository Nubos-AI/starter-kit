<?php

declare(strict_types=1);

use App\Actions\Engine\CreateObjectTypeAction;
use App\Actions\Engine\UpdateObjectTypeAction;
use App\Models\ObjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    app()->setLocale('de');

    /** @var callable(array<string, mixed>|null):ObjectType */
    $this->objectType = fn (?array $labels = null): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'companies',
        'slug' => 'companies',
        'name' => 'Companies',
        'is_system' => false,
        'i18n_labels' => $labels,
    ]);

    /** @var callable(ObjectType, array<string, mixed>):array<string, mixed>|null */
    $this->updateWrites = static function (ObjectType $objectType, array $input): ?array {
        DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());
        $written = null;

        ObjectType::updating(static function (ObjectType $updated) use (&$written): bool {
            $written = $updated->getDirty();

            return false;
        });

        app(UpdateObjectTypeAction::class)->execute($objectType, ['name' => 'Companies', ...$input]);

        return $written;
    };

    /** @var callable(array<string, mixed>):array<string, mixed> */
    $this->createWrites = static function (array $input): array {
        FakePresenceVerifier::holding(['object_types' => []]);
        DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());
        $created = [];

        ObjectType::creating(static function (ObjectType $objectType) use (&$created): void {
            $created = $objectType->getAttributes();

            throw new RuntimeException('creation captured');
        });

        expect(fn (): ObjectType => app(CreateObjectTypeAction::class)->execute([
            'key' => 'companies',
            'name' => 'Companies',
            'business_key_prefix' => 'FI',
            ...$input,
        ], slug: 'companies'))->toThrow(RuntimeException::class, 'creation captured');

        ObjectType::flushEventListeners();

        return $created;
    };

    /** @var callable(Closure():mixed):array<string, list<string>> */
    $this->validationErrorsOf = static function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };
});

afterEach(function (): void {
    ObjectType::flushEventListeners();
    AccessContext::forgetTenant();
});

it('writes the label of the active locale only and keeps the labels of every other locale', function (): void {
    $written = ($this->updateWrites)(($this->objectType)(['en' => 'Accounts']), ['label' => '  Firmen  ']);

    expect(json_decode((string) $written['i18n_labels'], true))->toBe(['en' => 'Accounts', 'de' => 'Firmen'])
        ->and($written)->not->toHaveKey('name');
});

it('removes the label of the active locale when the label is cleared and leaves the other locales alone', function (): void {
    $written = ($this->updateWrites)(($this->objectType)(['de' => 'Firmen', 'en' => 'Accounts']), ['label' => '']);

    expect(json_decode((string) $written['i18n_labels'], true))->toBe(['en' => 'Accounts']);
});

it('clears the labels entirely once the last locale loses its label', function (): void {
    $written = ($this->updateWrites)(($this->objectType)(['de' => 'Firmen']), ['label' => null]);

    expect($written)->toHaveKey('i18n_labels')
        ->and($written['i18n_labels'])->toBeNull();
});

it('leaves the labels untouched when an update does not carry a label', function (): void {
    $written = ($this->updateWrites)(($this->objectType)(['de' => 'Firmen']), ['name' => 'Renamed']);

    expect($written)->toBe(['name' => 'Renamed']);
});

it('refuses a label longer than the field allows on the field the form renders', function (): void {
    $errors = ($this->validationErrorsOf)(fn (): ?array => ($this->updateWrites)(($this->objectType)(), ['label' => str_repeat('a', 256)]));

    expect($errors)->toHaveKey('label');
});

it('refuses a label that is not text on creation', function (): void {
    FakePresenceVerifier::holding(['object_types' => []]);

    $errors = ($this->validationErrorsOf)(fn (): ObjectType => app(CreateObjectTypeAction::class)->execute([
        'key' => 'companies',
        'name' => 'Companies',
        'business_key_prefix' => 'FI',
        'label' => ['de' => 'Firmen'],
    ]));

    expect($errors)->toHaveKey('label');
});

it('stores the label of a new object type under the active locale next to its technical name', function (): void {
    $created = ($this->createWrites)(['label' => 'Firmen']);

    expect($created['name'])->toBe('Companies')
        ->and(json_decode((string) $created['i18n_labels'], true))->toBe(['de' => 'Firmen']);
});

it('creates an object type without labels when no label is given', function (): void {
    expect(($this->createWrites)(['label' => '   '])['i18n_labels'] ?? null)->toBeNull()
        ->and(($this->createWrites)([])['i18n_labels'] ?? null)->toBeNull();
});
