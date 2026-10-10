<?php

declare(strict_types=1);

use App\Casts\EncryptedJsonValue;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    AccessContext::tenant();

    $this->registry = app(FieldTypeRegistry::class);
    $this->field = ModelStub::make(FieldDefinition::class, [
        'key' => 'secret',
        'field_type' => FieldType::TextShort->value,
        'is_encrypted' => true,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('turns the plaintext into something that no longer contains it', function (): void {
    $ciphertext = $this->registry->write('top-secret-4711', $this->field);

    expect($ciphertext)->toBeString()
        ->and($ciphertext)->not->toBe('top-secret-4711')
        ->and($ciphertext)->not->toContain('top-secret-4711');
});

it('restores the original plaintext on the way back', function (): void {
    expect($this->registry->read($this->registry->write('confidential', $this->field), $this->field))->toBe('confidential');
});

it('never produces the same ciphertext twice for the same plaintext', function (): void {
    expect($this->registry->write('same-value', $this->field))
        ->not->toBe($this->registry->write('same-value', $this->field));
});

it('keeps an encrypted value out of the search index', function (): void {
    $ciphertext = $this->registry->write('secret', $this->field);

    expect($this->registry->toSearchable($ciphertext, $this->field))->toBeNull()
        ->and($this->registry->handlerFor(FieldType::TextShort)->toSearchable('secret', $this->field))->toBeNull();
});

it('surfaces a clear error for a tampered ciphertext instead of leaking anything', function (): void {
    expect(fn (): mixed => $this->registry->read('not-a-valid-ciphertext', $this->field))
        ->toThrow(RuntimeException::class, 'could not be decrypted');
});

it('rejects a non string encrypted value rather than mishandling it silently', function (): void {
    $cast = new EncryptedJsonValue;

    expect(fn (): mixed => $cast->get($this->field, (string) $this->field->key, ['unexpected' => 'array'], []))
        ->toThrow(RuntimeException::class, 'must be a string');
});
