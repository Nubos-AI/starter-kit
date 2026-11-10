<?php

declare(strict_types=1);

use App\Actions\Api\CreateApiTokenAction;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticPresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [], ['companies', 'contacts', 'deals']));
    new FakeFieldVisibilityResolver;
    AccessContext::unrestrictedRowAccess();

    $this->action = app(CreateApiTokenAction::class);

    /** @var callable(list<array{objectType: string, levels: list<string>}>):array<string, mixed> */
    $this->input = static fn (array $access): array => [
        'name' => 'Integration',
        'objectTypeAccess' => $access,
    ];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
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

it('refuses an object type whose slug would mint the global records ability and widen the token to every object type', function (string $level, array $granted): void {
    StaticPresenceVerifier::install(['object_types' => ['records']]);
    app()->instance(ObjectTypeRegistry::class, new StaticObjectTypeRegistry([], [], ['records', 'companies']));

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant(...$granted);

    try {
        app(CreateApiTokenAction::class)->execute($actor, $this->tenant, ($this->input)([
            ['objectType' => 'records', 'levels' => [$level]],
        ]));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['objectTypeAccess.0.objectType']);

        return;
    }

    $this->fail('the action minted the global records ability from an object type slug');
})->with([
    'read' => ['read', ['records.view']],
    'write' => ['write', ['records.create', 'records.update', 'records.delete']],
]);

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
        ->and($shape->isScopedToTenant('users', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasConjunctiveCondition('users', 'is_service'))->toBeTrue();
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

it('refuses a read ability when the creator cannot read every field of the object type', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['contacts']]);
    new FakeFieldVisibilityResolver(['salary']);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('contacts.view');

    try {
        app(CreateApiTokenAction::class)->execute($actor, $this->tenant, ($this->input)([
            ['objectType' => 'contacts', 'levels' => ['read']],
        ]));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['objectTypeAccess.0.levels'])
            ->and($exception->errors()['objectTypeAccess.0.levels'][0])->toBe(__('i18n.backend.support.api.api_token_rights_guard.a_token_may_not_unlock_fields_that_are_hidden_from_you'));

        return;
    }

    $this->fail('the action minted a token that reads fields hidden from its creator');
});

it('refuses a write ability when the creator cannot write every field of the object type', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['contacts']]);
    new FakeFieldVisibilityResolver([], ['salary']);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('contacts.create', 'contacts.update', 'contacts.delete');

    expect(fn (): NewAccessToken => app(CreateApiTokenAction::class)->execute($actor, $this->tenant, ($this->input)([
        ['objectType' => 'contacts', 'levels' => ['write']],
    ])))->toThrow(ValidationException::class);
});

it('refuses any ability on an object type whose rows are narrowed for the creator by team access rules', function (string $level, array $granted): void {
    StaticPresenceVerifier::install(['object_types' => ['contacts']]);
    $rules = AccessContext::rowAccessRules([
        ModelStub::ulid('object-type-contacts') => [[['field' => 'region', 'operator' => 'equals', 'value' => 'north']]],
    ]);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant(...$granted);

    try {
        app(CreateApiTokenAction::class)->execute($actor, $this->tenant, ($this->input)([
            ['objectType' => 'contacts', 'levels' => [$level]],
        ]));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['objectTypeAccess.0.levels'])
            ->and($exception->errors()['objectTypeAccess.0.levels'][0])->toBe(__('i18n.backend.support.api.api_token_rights_guard.a_token_may_not_bypass_the_record_restrictions_that_apply_to_you'))
            ->and($rules->resolvedFor)->toBe([(string) $actor->getKey()]);

        return;
    }

    $this->fail('the action minted a token that escapes the row restrictions of its creator');
})->with([
    'read' => ['read', ['contacts.view']],
    'write' => ['write', ['contacts.create', 'contacts.update', 'contacts.delete']],
]);

it('mints a token when row rules narrow only a different object type and every field is visible', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['companies']]);
    AccessContext::rowAccessRules([
        ModelStub::ulid('object-type-contacts') => [[['field' => 'region', 'operator' => 'equals', 'value' => 'north']]],
    ]);

    $actor = AccessContext::user($this->tenant);
    AccessContext::grant('companies.view');

    $shape = QueryShape::attemptedBy(fn (): NewAccessToken => app(CreateApiTokenAction::class)->execute(
        $actor,
        $this->tenant,
        ($this->input)([['objectType' => 'companies', 'levels' => ['read']]]),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue();
});

it('binds the minted token to its creator inside one transaction so the token can never act beyond that user', function (): void {
    StaticPresenceVerifier::install(['object_types' => ['companies']]);

    $actor = AccessContext::user($this->tenant, [], 'token-creator');
    AccessContext::grant('companies.view');

    $serviceRow = [
        'id' => ModelStub::ulid('service-user'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'is_service' => true,
        'email' => 'service@service.invalid',
    ];

    $connection = StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'returning') => [['id' => 43]],
            str_contains($sql, 'from "users"') => [$serviceRow],
            default => [],
        },
        static fn (): int => 1,
    );

    $token = app(CreateApiTokenAction::class)->execute($actor, $this->tenant, ($this->input)([
        ['objectType' => 'companies', 'levels' => ['read']],
    ]));

    $binding = collect($connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "personal_access_tokens"'));

    expect($token->accessToken->tokenable_id)->toBe($serviceRow['id'])
        ->and($binding)->not->toBeNull()
        ->and($binding['sql'])->toContain('"creator_id" = ?')
        ->and($binding['bindings'])->toContain((string) $actor->getKey())
        ->and($binding['bindings'])->toContain(43)
        ->and($connection->openedTransactions)->toBe(1);
});
