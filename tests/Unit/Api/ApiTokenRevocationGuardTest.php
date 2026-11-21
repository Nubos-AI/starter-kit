<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Api\ApiTokenRevocationGuard;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Users\ManageableUserDirectory;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->directory = Mockery::mock(ManageableUserDirectory::class);
    $this->guard = new ApiTokenRevocationGuard($this->subsetGuard, $this->directory);

    /** @var callable(string, bool):User */
    $this->userNamed = static fn (string $seed, bool $isService = false): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid($seed),
        'is_service' => $isService,
    ]);

    $this->actor = ($this->userNamed)('token-admin');
});

it('lets the owner revoke an own personal token without judging the account', function (): void {
    $this->subsetGuard->shouldNotReceive('maySteerAccount');

    expect($this->guard->mayRevoke($this->actor, ($this->userNamed)('token-admin')))->toBeTrue();
});

it('lets a token manager revoke a service token without judging the account', function (): void {
    $this->subsetGuard->shouldNotReceive('maySteerAccount');

    expect($this->guard->mayRevoke($this->actor, ($this->userNamed)('integration', true)))->toBeTrue();
});

it('lets a token manager revoke the personal token of an account the manager may steer', function (): void {
    $colleague = ($this->userNamed)('colleague');

    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->actor, $colleague)->andReturnTrue();

    expect($this->guard->mayRevoke($this->actor, $colleague))->toBeTrue();
});

it('refuses the personal token of an account that holds more rights than the token manager', function (): void {
    $owner = ($this->userNamed)('tenant-owner');

    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->actor, $owner)->andReturnFalse();

    expect($this->guard->mayRevoke($this->actor, $owner))->toBeFalse();
});

it('judges only foreign personal token owners in one batch when a token list is put together', function (): void {
    $steerable = ($this->userNamed)('colleague');
    $beyond = ($this->userNamed)('tenant-owner');
    $keys = [ModelStub::ulid('colleague'), ModelStub::ulid('tenant-owner')];

    $this->directory->shouldReceive('roleIdsByUser')->once()->with($keys)->andReturn([]);
    $this->directory->shouldReceive('teamIdsByUser')->once()->with($keys)->andReturn([]);
    $this->subsetGuard->shouldReceive('steerableAccountKeys')->once()->with($this->actor, $keys, [], [])->andReturn([ModelStub::ulid('colleague')]);

    $revocable = $this->guard->revocableOwnerKeys($this->actor, [
        ($this->userNamed)('token-admin'),
        ($this->userNamed)('integration', true),
        $steerable,
        $beyond,
        $beyond,
    ]);

    expect($revocable)->toEqualCanonicalizing([
        ModelStub::ulid('token-admin'),
        ModelStub::ulid('integration'),
        ModelStub::ulid('colleague'),
    ]);
});

it('asks no directory when every token belongs to the actor or a service account', function (): void {
    $this->directory->shouldNotReceive('roleIdsByUser');
    $this->subsetGuard->shouldNotReceive('steerableAccountKeys');

    expect($this->guard->revocableOwnerKeys($this->actor, [$this->actor, ($this->userNamed)('integration', true)]))
        ->toEqualCanonicalizing([ModelStub::ulid('token-admin'), ModelStub::ulid('integration')]);
});
