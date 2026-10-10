<?php

declare(strict_types=1);

use App\Enums\CustomFields\ReservedFieldKey;
use App\Support\Engine\ShadowedRecordFieldKeys;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->shadowed = (new ShadowedRecordFieldKeys)->all();
});

it('shadows every spine column a field key could otherwise overwrite', function (string $key): void {
    expect($this->shadowed)->toContain($key);
})->with(['id', 'version', 'data', 'record_number', 'owner_id', 'tenant_id', 'object_type_id', 'deleted_at']);

it('shadows every record method a field key could otherwise hide', function (string $key): void {
    expect($this->shadowed)->toContain($key);
})->with(['parent', 'children', 'ancestors', 'descendants', 'save', 'delete']);

it('leaves a key that collides with nothing available', function (): void {
    expect($this->shadowed)->not->toContain('headline')
        ->and($this->shadowed)->not->toContain('company_name');
});

it('keeps the name field reserved so it cannot be deleted from an object type', function (): void {
    expect(ReservedFieldKey::isReserved('name'))->toBeTrue()
        ->and(ReservedFieldKey::isReserved('headline'))->toBeFalse()
        ->and(ReservedFieldKey::Name->value)->toBe('name');
});
