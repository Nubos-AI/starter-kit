<?php

declare(strict_types=1);

use App\Actions\Sso\UpdateSsoConnectionAction;
use App\Actions\Users\CreateUserAction;
use App\Actions\Users\UpdateProfileAction;
use App\Actions\Users\UpdateUserAction;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Users\EmailListParser;
use App\Support\Users\ServiceAccountEmail;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('acme');

    FakePresenceVerifier::holding(['users' => []]);

    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->andReturnNull();
    app()->instance(SsoConnectionLookup::class, $lookup);

    $this->user = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@acme.de',
    ]);

    $this->serviceAddress = 'service-'.strtolower((string) $this->tenant->getKey()).'@service.invalid';
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('mints an unpredictable address on the reserved domain for every new service identity', function (): void {
    $addresses = app(ServiceAccountEmail::class);

    $first = $addresses->generate();
    $second = $addresses->generate();

    expect($first)->toEndWith('@service.invalid')
        ->and($second)->toEndWith('@service.invalid')
        ->and($first)->not->toBe($second)
        ->and($first)->not->toBe($this->serviceAddress);
});

it('refuses a self registration on the reserved service domain', function (string $email): void {
    expect(fn () => app(CreateUserAction::class)->create([
        'company_name' => 'Nordlicht Handels GmbH',
        'salutation' => Salutation::Mix->value,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => $email,
        'password' => 'Str0ng-Passphrase!42',
        'password_confirmation' => 'Str0ng-Passphrase!42',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('email');
    });
})->with([
    'exact' => 'service-01jabcdefghjkmnpqrstvwxyz0@service.invalid',
    'upper case' => 'Service-X@SERVICE.invalid',
    'sub domain' => 'someone@mail.service.invalid',
]);

it('refuses a profile or invited user address on the reserved service domain', function (string $actionClass, ?string $status): void {
    $this->user->setRawAttributes([...$this->user->getAttributes(), 'status' => $status], true);

    expect(fn () => app($actionClass)->execute($this->user, [
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => $this->serviceAddress,
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('email');
    });
})->with([
    'own profile' => [UpdateProfileAction::class, null],
    'invited user' => [UpdateUserAction::class, UserStatus::Invited->value],
]);

it('treats an invitation address on the reserved service domain as invalid', function (): void {
    $parser = app(EmailListParser::class);

    expect($parser->invalid(['grace@acme.de', 'service-x@service.invalid']))->toBe(['service-x@service.invalid']);
});

it('refuses the reserved service domain as a single sign on domain', function (): void {
    expect(fn () => app(UpdateSsoConnectionAction::class)->execute($this->user, [
        'is_enabled' => false,
        'is_enforced' => false,
        'is_jit_enabled' => false,
        'allowed_domains' => ['service.invalid'],
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('allowed_domains');
    });
});
