<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncPermissionOverridesAction;
use App\Models\Permission;
use App\Support\Authorization\AuthorizationDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\FakePresenceVerifier;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->directory = new FakeAuthorizationDirectory;
    app()->instance(AuthorizationDirectory::class, $this->directory);

    /** @var callable(string):Permission */
    $this->permission = fn (string $name): Permission => ModelStub::make(Permission::class, [
        'id' => ModelStub::ulid($name),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $name,
    ]);

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'actor');
    AccessContext::actAs($this->actor);

    $this->holder = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'holder');

    $this->action = fn (): SyncPermissionOverridesAction => app(SyncPermissionOverridesAction::class);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('rejects a permission id the bound tenant does not own', function (): void {
    FakePresenceVerifier::holding(['permissions' => []]);
    AccessContext::grant();

    expect(fn () => ($this->action)()->execute($this->holder, [ModelStub::ulid('ghost')]))
        ->toThrow(ValidationException::class);
});

it('refuses to withdraw a permission the actor does not hold themselves', function (): void {
    $denied = ($this->permission)('members.password');

    FakePresenceVerifier::holding(['permissions' => [(string) $denied->getKey()]]);
    AccessContext::grant('members.view');

    $this->directory->withPermissions([$denied])->withDeniedPermissions($this->holder, []);

    expect(fn () => ($this->action)()->execute($this->holder, [(string) $denied->getKey()]))
        ->toThrow(AuthorizationException::class);
});

it('lets the actor withdraw a permission they hold themselves', function (): void {
    $denied = ($this->permission)('members.password');

    FakePresenceVerifier::holding(['permissions' => [(string) $denied->getKey()]]);
    $resolver = AccessContext::grant('members.password');

    $this->directory->withPermissions([$denied])->withDeniedPermissions($this->holder, []);

    $reached = WriteAttempt::reachedTheDatabase(
        fn () => ($this->action)()->execute($this->holder, [(string) $denied->getKey()]),
    );

    expect($reached)->toBeTrue()
        ->and($resolver->askedFor)->toBe(['members.password']);
});

it('refuses to lift a denial on a permission the actor does not hold', function (): void {
    $denied = ($this->permission)('members.password');

    FakePresenceVerifier::holding(['permissions' => [(string) $denied->getKey()]]);
    AccessContext::grant('members.view');

    $this->directory
        ->withPermissions([$denied])
        ->withDeniedPermissions($this->holder, [(string) $denied->getKey()]);

    expect(fn () => ($this->action)()->execute($this->holder, []))
        ->toThrow(AuthorizationException::class);
});

it('asks nothing when the submitted denials match the stored ones', function (): void {
    $denied = ($this->permission)('members.password');

    FakePresenceVerifier::holding(['permissions' => [(string) $denied->getKey()]]);
    $resolver = AccessContext::grant();

    $this->directory
        ->withPermissions([$denied])
        ->withDeniedPermissions($this->holder, [(string) $denied->getKey()]);

    $reached = WriteAttempt::reachedTheDatabase(
        fn () => ($this->action)()->execute($this->holder, [(string) $denied->getKey()]),
    );

    expect($reached)->toBeTrue()
        ->and($resolver->askedFor)->toBeEmpty();
});

it('sweeps every stored denial away when nothing at all is submitted', function (): void {
    FakePresenceVerifier::holding(['permissions' => []]);
    AccessContext::grant();

    $this->directory->withPermissions([])->withDeniedPermissions($this->holder, []);

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    ($this->action)()->execute($this->holder, []);

    expect($connection->writtenStatements)->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toStartWith('delete from "permission_overrides"')
        ->and($connection->writtenStatements[0]['sql'])->not->toContain('not in');
});

it('spares the submitted denial from the sweep and writes it back', function (): void {
    $denied = ($this->permission)('members.password');

    FakePresenceVerifier::holding(['permissions' => [(string) $denied->getKey()]]);
    AccessContext::grant('members.password');

    $this->directory->withPermissions([$denied])->withDeniedPermissions($this->holder, []);

    $connection = StaticQueryConnection::install(
        static fn (): array => [],
        static fn (): int => 1,
    );

    ($this->action)()->execute($this->holder, [(string) $denied->getKey()]);

    expect($connection->writtenStatements[0]['sql'])->toContain('not in')
        ->and($connection->writtenStatements[0]['bindings'])->toContain((string) $denied->getKey())
        ->and($connection->writtenStatements[1]['sql'])->toStartWith('insert into "permission_overrides"');
});
