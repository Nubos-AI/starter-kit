<?php

declare(strict_types=1);

use App\Actions\Tenancy\ProvisionTenantAction;
use App\Actions\Users\CreateUserAction;
use App\DTOs\Tenancy\TenantRegistrationData;
use App\Enums\Users\Salutation;
use App\Models\User;
use Illuminate\Validation\PresenceVerifierInterface;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $presence = Mockery::mock(PresenceVerifierInterface::class);
    $presence->shouldReceive('getCount')->andReturn(0);
    app('validator')->setPresenceVerifier($presence);

    $this->provision = Mockery::mock(ProvisionTenantAction::class);
    $this->action = fn (): CreateUserAction => new CreateUserAction($this->provision);

    /** @var array<string, string> */
    $this->input = [
        'company_name' => 'Nordlicht Handels GmbH',
        'salutation' => Salutation::Mix->value,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@nordlicht.test',
        'password' => 'Str0ng-Passphrase!42',
        'password_confirmation' => 'Str0ng-Passphrase!42',
    ];
});

it('refuses a registration without a company name before anything is provisioned', function (): void {
    $this->provision->shouldNotReceive('execute');

    $input = $this->input;
    unset($input['company_name']);

    expect(fn () => ($this->action)()->create($input))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('company_name');
        });
});

it('provisions a tenant of its own for the registering user from the validated input only', function (): void {
    $owner = AccessContext::user(AccessContext::tenant());

    $this->provision->shouldReceive('execute')
        ->once()
        ->withArgs(function (TenantRegistrationData $data): bool {
            return $data->companyName === 'Nordlicht Handels GmbH'
                && $data->salutation === Salutation::Mix
                && $data->firstName === 'Ada'
                && $data->lastName === 'Lovelace'
                && $data->email === 'ada@nordlicht.test'
                && $data->password === 'Str0ng-Passphrase!42';
        })
        ->andReturn($owner);

    $created = ($this->action)()->create([
        ...$this->input,
        'tenant_id' => 'foreign-tenant',
        'is_service' => '1',
    ]);

    expect($created)->toBe($owner);

    AccessContext::forgetTenant();
});

it('falls back to the unknown salutation when none is chosen', function (): void {
    $input = $this->input;
    unset($input['salutation']);

    $this->provision->shouldReceive('execute')
        ->once()
        ->withArgs(fn (TenantRegistrationData $data): bool => $data->salutation === Salutation::Unknown)
        ->andReturn(new User);

    ($this->action)()->create($input);
});
