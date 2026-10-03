<?php

declare(strict_types=1);

use App\Enums\Api\ApiAccessLevel;
use App\Models\ObjectType;
use App\Support\Api\ApiAbilityMap;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->map = new ApiAbilityMap;

    /** @var callable(list<string>):PersonalAccessToken */
    $this->token = static function (array $abilities): PersonalAccessToken {
        $token = new PersonalAccessToken;
        $token->abilities = $abilities;

        return $token;
    };

    /** @var callable(string):ObjectType */
    $this->objectType = static function (string $slug): ObjectType {
        $objectType = new ObjectType;
        $objectType->slug = $slug;

        return $objectType;
    };
});

it('turns the access matrix into one ability per object type and level in a stable order', function (): void {
    $abilities = $this->map->abilitiesForAccess([
        ['objectType' => 'contacts', 'levels' => ['write', 'read']],
        ['objectType' => 'companies', 'levels' => ['read']],
    ]);

    expect($abilities)->toBe(['contacts:read', 'contacts:write', 'companies:read']);
});

it('emits no duplicate ability for a duplicated matrix row', function (): void {
    $abilities = $this->map->abilitiesForAccess([
        ['objectType' => 'companies', 'levels' => ['read']],
        ['objectType' => 'companies', 'levels' => ['read']],
    ]);

    expect($abilities)->toBe(['companies:read']);
});

it('accepts only the bound object type or the global ability on a slug bound route', function (): void {
    expect($this->map->satisfies(['companies:read'], 'companies', ApiAccessLevel::Read))->toBeTrue()
        ->and($this->map->satisfies(['companies:read'], 'contacts', ApiAccessLevel::Read))->toBeFalse()
        ->and($this->map->satisfies(['companies:read'], 'companies', ApiAccessLevel::Write))->toBeFalse()
        ->and($this->map->satisfies(['records:read'], 'contacts', ApiAccessLevel::Read))->toBeTrue()
        ->and($this->map->satisfies(['records:read'], 'contacts', ApiAccessLevel::Write))->toBeFalse();
});

it('never lets a read ability open a write level on a route without a slug', function (): void {
    expect($this->map->satisfies(['companies:read'], null, ApiAccessLevel::Write))->toBeFalse()
        ->and($this->map->satisfies(['companies:read', 'contacts:read'], null, ApiAccessLevel::Write))->toBeFalse()
        ->and($this->map->satisfies([], null, ApiAccessLevel::Read))->toBeFalse()
        ->and($this->map->satisfies(['companies'], null, ApiAccessLevel::Read))->toBeFalse();
});

it('admits a slug less route on any read ability and leaves the narrowing to the object type check', function (): void {
    expect($this->map->satisfies(['companies:read'], null, ApiAccessLevel::Read))->toBeTrue()
        ->and($this->map->satisfiesObjectType(($this->token)(['companies:read']), ($this->objectType)('contacts'), ApiAccessLevel::Read))->toBeFalse()
        ->and($this->map->satisfiesObjectType(($this->token)(['companies:read']), ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeTrue();
});

it('fails the object type check closed without a token', function (): void {
    expect($this->map->satisfiesObjectType(null, ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeFalse();
});

it('fails the object type check closed for a session bound transient token', function (): void {
    expect($this->map->satisfiesObjectType(new TransientToken, ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeFalse();
});

it('fails the object type check closed when the object type does not resolve', function (): void {
    expect($this->map->satisfiesObjectType(($this->token)(['companies:read']), null, ApiAccessLevel::Read))->toBeFalse();
});

it('accepts a token carrying the ability for the object type slug', function (): void {
    expect($this->map->satisfiesObjectType(($this->token)(['companies:read']), ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeTrue()
        ->and($this->map->satisfiesObjectType(($this->token)(['companies:write']), ($this->objectType)('companies'), ApiAccessLevel::Write))->toBeTrue();
});

it('rejects a token carrying the ability for another object type slug', function (): void {
    expect($this->map->satisfiesObjectType(($this->token)(['contacts:read']), ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeFalse()
        ->and($this->map->satisfiesObjectType(($this->token)(['companies:read']), ($this->objectType)('companies'), ApiAccessLevel::Write))->toBeFalse()
        ->and($this->map->satisfiesObjectType(($this->token)([]), ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeFalse();
});

it('keeps the global records ability working through the object type check', function (): void {
    expect($this->map->satisfiesObjectType(($this->token)(['records:read']), ($this->objectType)('companies'), ApiAccessLevel::Read))->toBeTrue()
        ->and($this->map->satisfiesObjectType(($this->token)(['records:read']), ($this->objectType)('companies'), ApiAccessLevel::Write))->toBeFalse();
});

it('maps read onto view and write onto the three mutating permissions', function (): void {
    expect($this->map->permissionsFor('companies', ApiAccessLevel::Read))->toBe(['companies.view'])
        ->and($this->map->permissionsFor('companies', ApiAccessLevel::Write))
        ->toBe(['companies.create', 'companies.update', 'companies.delete']);
});

it('derives the granted permissions from the abilities of a token', function (): void {
    expect($this->map->grantsFor(['companies:read', 'contacts:write']))->toBe([
        'companies.view' => true,
        'contacts.create' => true,
        'contacts.update' => true,
        'contacts.delete' => true,
    ]);
});

it('grants nothing from the global ability alone so a matrix token stays fail closed', function (): void {
    expect($this->map->grantsFor(['records:read', 'records:write']))->toBe([]);
});

it('ignores an unparsable ability', function (): void {
    expect($this->map->grantsFor(['*', 'companies', 'companies:admin', ':read']))->toBe([]);
});
