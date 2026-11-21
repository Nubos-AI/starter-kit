<?php

declare(strict_types=1);

use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Actions\Engine\CreateObjectTypeAction;
use App\Enums\CustomFields\FieldType;
use App\Models\ObjectType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(Closure():mixed):array<string, list<string>> */
    $this->validationErrorsOf = function (Closure $callback): array {
        try {
            $callback();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    /** @var callable(FakePresenceVerifier, string, string):array<string, mixed>|null */
    $this->lookupOf = static fn (FakePresenceVerifier $verifier, string $table, string $column): ?array => collect($verifier->lookups)
        ->first(static fn (array $lookup): bool => $lookup['table'] === $table && $lookup['column'] === $column);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses an object type key the tenant already uses with a German message instead of a database error', function (): void {
    FakePresenceVerifier::holding(['object_types' => ['companies']]);

    $errors = ($this->validationErrorsOf)(fn (): ObjectType => app(CreateObjectTypeAction::class)->execute([
        'key' => 'companies',
        'name' => 'Firmen',
        'business_key_prefix' => 'FI',
    ]));

    expect($errors['key'] ?? [])->toBe(['Schlüssel ist bereits vergeben.']);
});

it('checks object type key and slug only against live object types of the current tenant', function (): void {
    $verifier = FakePresenceVerifier::holding(['object_types' => []]);
    DB::shouldReceive('transaction')->once()->andReturn(new ObjectType);

    app(CreateObjectTypeAction::class)->execute(
        ['key' => 'companies', 'name' => 'Firmen', 'business_key_prefix' => 'FI'],
        slug: 'companies',
    );

    foreach (['key', 'slug'] as $column) {
        $lookup = ($this->lookupOf)($verifier, 'object_types', $column);

        expect($lookup)->not->toBeNull()
            ->and($lookup['extra']['tenant_id'] ?? null)->toBe($this->tenantId)
            ->and($lookup['extra']['deleted_at'] ?? null)->toBe('NULL');
    }
});

it('refuses a field key the object type already uses with a German message instead of a database error', function (): void {
    FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId], 'field_definitions' => ['industry']]);

    $errors = ($this->validationErrorsOf)(fn () => app(CreateFieldDefinitionAction::class)->execute([
        'object_type_id' => $this->objectTypeId,
        'key' => 'industry',
        'field_type' => FieldType::TextShort->value,
    ]));

    expect($errors['key'] ?? [])->toBe(['Schlüssel ist bereits vergeben.']);
});

it('checks a field key only against live fields of the same object type and tenant', function (): void {
    $verifier = FakePresenceVerifier::holding(['object_types' => [$this->objectTypeId], 'field_definitions' => []]);

    QueryShape::attemptedBy(fn () => app(CreateFieldDefinitionAction::class)->execute([
        'object_type_id' => $this->objectTypeId,
        'key' => 'industry',
        'field_type' => FieldType::TextShort->value,
    ]));

    $lookup = ($this->lookupOf)($verifier, 'field_definitions', 'key');

    expect($lookup)->not->toBeNull()
        ->and($lookup['extra'])->toMatchArray([
            'tenant_id' => $this->tenantId,
            'object_type_id' => $this->objectTypeId,
            'deleted_at' => 'NULL',
        ]);
});
