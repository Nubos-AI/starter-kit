<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiTokenAction;
use App\Actions\Api\ProvisionServiceUserAction;
use App\Support\Api\ApiAbilityMap;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticPresenceVerifier;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->action = new CreateApiTokenAction(
        new ProvisionServiceUserAction,
        new ApiAbilityMap,
    );

    /** @var callable(list<array{objectType: string, levels: list<string>}>):array<string, mixed> */
    $this->input = static fn (array $access): array => [
        'name' => 'Integration',
        'objectTypeAccess' => $access,
    ];
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('accepts an object type only when it exists unscoped to no tenant and is not a system type', function (): void {
    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('companies.view');

    $shape = QueryShape::attemptedBy(fn (): NewAccessToken => $this->action->execute(
        $actor,
        $this->tenant,
        ($this->input)([['objectType' => 'companies', 'levels' => ['read']]]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('object_types'))->toBeTrue()
        ->and($shape->sql)->toContain('"slug" = ?')
        ->and($shape->sql)->toContain('"is_system" = ?')
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->bindings)->toContain((string) $this->tenant->getKey())
        ->and($shape->bindings)->toContain('companies');
});

it('refuses to mint a read ability the creator does not hold themselves', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['companies']]);

    $actor = AccessContext::user($this->tenant);
    $resolver = AccessContext::grant('contacts.view');

    try {
        $this->action->execute($actor, $this->tenant, ($this->input)([
            ['objectType' => 'companies', 'levels' => ['read']],
        ]));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['objectTypeAccess.0.levels'])
            ->and($resolver->askedFor)->toBe(['companies.view']);

        return;
    }

    $this->fail('the action minted a token beyond the rights of its creator');
});

it('refuses a write ability when the creator holds only part of the three mutating rights', function (string ...$granted): void {
    StaticPresenceVerifier::install(['object_types' => ['companies']]);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant(...$granted);

    expect(fn (): NewAccessToken => $this->action->execute($actor, $this->tenant, ($this->input)([
        ['objectType' => 'companies', 'levels' => ['write']],
    ])))->toThrow(ValidationException::class);
})->with([
    ['companies.create'],
    ['companies.create', 'companies.update'],
    ['companies.update', 'companies.delete'],
]);

it('names every offending matrix row and never only the first', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['companies', 'contacts', 'deals']]);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('contacts.view');

    try {
        $this->action->execute($actor, $this->tenant, ($this->input)([
            ['objectType' => 'companies', 'levels' => ['read']],
            ['objectType' => 'contacts', 'levels' => ['read']],
            ['objectType' => 'deals', 'levels' => ['read']],
        ]));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['objectTypeAccess.0.levels', 'objectTypeAccess.2.levels']);

        return;
    }

    $this->fail('the action minted a token beyond the rights of its creator');
});

it('passes the rights guard and reaches the service user provisioning once the creator holds everything', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['companies']]);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('companies.create', 'companies.update', 'companies.delete');

    $shape = QueryShape::attemptedBy(fn (): NewAccessToken => $this->action->execute(
        $actor,
        $this->tenant,
        ($this->input)([['objectType' => 'companies', 'levels' => ['write']]]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->bindings)->toContain('service-'.strtolower((string) $this->tenant->getKey()).'@service.invalid');
});

it('rejects an empty matrix, an unknown level and a missing name before anything else happens', function (array $input): void {
    StaticPresenceVerifier::install(['object_types' => ['companies']]);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('companies.view', 'companies.create', 'companies.update', 'companies.delete');

    expect(fn (): NewAccessToken => $this->action->execute($actor, $this->tenant, $input))
        ->toThrow(ValidationException::class);
})->with([
    'empty matrix' => [['name' => 'Integration', 'objectTypeAccess' => []]],
    'missing matrix' => [['name' => 'Integration']],
    'missing name' => [['objectTypeAccess' => [['objectType' => 'companies', 'levels' => ['read']]]]],
    'wildcard level' => [['name' => 'Integration', 'objectTypeAccess' => [['objectType' => 'companies', 'levels' => ['*']]]]],
    'empty levels' => [['name' => 'Integration', 'objectTypeAccess' => [['objectType' => 'companies', 'levels' => []]]]],
    'expiry in the past' => [['name' => 'Integration', 'objectTypeAccess' => [['objectType' => 'companies', 'levels' => ['read']]], 'expiresAt' => '2000-01-01T00:00:00Z']],
]);
