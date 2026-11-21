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

it('decides the object type check from the token, the object type and the level and fails closed', function (string $tokenKind, array $abilities, ?string $slug, ApiAccessLevel $level, bool $expected): void {
    $token = match ($tokenKind) {
        'none' => null,
        'transient' => new TransientToken,
        default => ($this->token)($abilities),
    };

    $objectType = $slug === null ? null : ($this->objectType)($slug);

    expect($this->map->satisfiesObjectType($token, $objectType, $level))->toBe($expected);
})->with([
    'no token' => ['none', [], 'companies', ApiAccessLevel::Read, false],
    'session bound transient token' => ['transient', [], 'companies', ApiAccessLevel::Read, false],
    'object type does not resolve' => ['personal', ['companies:read'], null, ApiAccessLevel::Read, false],
    'read ability for the object type slug' => ['personal', ['companies:read'], 'companies', ApiAccessLevel::Read, true],
    'write ability for the object type slug' => ['personal', ['companies:write'], 'companies', ApiAccessLevel::Write, true],
    'ability for another object type slug' => ['personal', ['contacts:read'], 'companies', ApiAccessLevel::Read, false],
    'read ability on a write level' => ['personal', ['companies:read'], 'companies', ApiAccessLevel::Write, false],
    'no ability at all' => ['personal', [], 'companies', ApiAccessLevel::Read, false],
    'global records read ability' => ['personal', ['records:read'], 'companies', ApiAccessLevel::Read, true],
    'global records read ability on a write level' => ['personal', ['records:read'], 'companies', ApiAccessLevel::Write, false],
]);

it('maps read onto view and write onto the three mutating permissions', function (): void {
    expect($this->map->permissionsFor('companies', ApiAccessLevel::Read))->toBe(['companies.view'])
        ->and($this->map->permissionsFor('companies', ApiAccessLevel::Write))
        ->toBe(['companies.create', 'companies.update', 'companies.delete']);
});

it('reads the access matrix back from the abilities of a token', function (): void {
    expect($this->map->accessForAbilities(['companies:read', 'contacts:write', 'companies:write', 'companies:read']))->toBe([
        ['objectType' => 'companies', 'levels' => ['read', 'write']],
        ['objectType' => 'contacts', 'levels' => ['write']],
    ]);
});

it('ignores an unparsable ability when it reads the access matrix back', function (): void {
    expect($this->map->accessForAbilities(['*', 'companies', 'companies:admin', ':read']))->toBe([]);
});
