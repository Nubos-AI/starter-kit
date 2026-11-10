<?php

declare(strict_types=1);

use App\Actions\Governance\CreateAbsenceDelegationAction;
use App\Actions\Governance\UpdateAbsenceDelegationAction;
use App\Models\AbsenceDelegation;
use App\Models\User;
use App\Support\Authorization\PermissionSubsetGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeManagedUserResolver;
use Tests\Support\Doubles\StaticPresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant, [], 'absence-manager'));
    $this->subject = AccessContext::user($this->tenant, [], 'absent-approver');
    $this->delegateId = ModelStub::ulid('colleague');

    AccessContext::grant('absences.manage');

    StaticPresenceVerifier::install(['users' => [
        (string) $this->actor->getKey(),
        (string) $this->subject->getKey(),
        $this->delegateId,
    ]]);

    $this->connection = StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_contains($sql, 'from "users"') && in_array((string) $this->subject->getKey(), $bindings, true) => [$this->subject->getAttributes()],
            str_contains($sql, 'exists') => [['exists' => false]],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->steering = function (bool $maySteer): void {
        $guard = Mockery::mock(PermissionSubsetGuard::class);
        $guard->shouldReceive('maySteerAccount')
            ->withArgs(fn (User $actor, User $target): bool => $actor->getKey() === $this->actor->getKey() && $target->getKey() === $this->subject->getKey())
            ->andReturn($maySteer);

        app()->instance(PermissionSubsetGuard::class, $guard);
    };

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    $this->input = fn (array $overrides = []): array => [
        'userId' => (string) $this->subject->getKey(),
        'delegateId' => $this->delegateId,
        'startsAt' => '2026-04-01T00:00:00+00:00',
        'endsAt' => '2026-04-08T00:00:00+00:00',
        ...$overrides,
    ];

    $this->delegation = fn (): AbsenceDelegation => ModelStub::make(AbsenceDelegation::class, [
        'id' => ModelStub::ulid('existing-delegation'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'user_id' => (string) $this->subject->getKey(),
        'delegate_id' => $this->delegateId,
        'starts_at' => '2026-04-01 00:00:00',
        'ends_at' => '2026-04-08 00:00:00',
    ], ['user' => $this->subject]);

    /**
     * @return array<string, list<string>>
     */
    $this->errorsOf = function (callable $write): array {
        try {
            $write();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted a delegation it should have refused');
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('refuses to record an absence for a person outside the management reach of the actor', function (): void {
    FakeManagedUserResolver::reachingTeams([])->managing([]);
    ($this->steering)(true);

    $errors = ($this->errorsOf)(fn () => app(CreateAbsenceDelegationAction::class)->execute($this->actor, ($this->input)()));

    expect($errors)->toHaveKey('userId')
        ->and($this->connection->writtenStatements)->toBeEmpty();
});

it('refuses to record an absence for a person who holds more rights than the actor', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->subject->getKey()]);
    ($this->steering)(false);

    $errors = ($this->errorsOf)(fn () => app(CreateAbsenceDelegationAction::class)->execute($this->actor, ($this->input)()));

    expect($errors)->toHaveKey('userId')
        ->and($this->connection->writtenStatements)->toBeEmpty();
});

it('refuses an actor who names themselves the delegate of another person', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->subject->getKey()]);
    ($this->steering)(true);

    $errors = ($this->errorsOf)(fn () => app(CreateAbsenceDelegationAction::class)->execute($this->actor, ($this->input)([
        'delegateId' => (string) $this->actor->getKey(),
    ])));

    expect($errors)->toHaveKey('delegateId')
        ->and($this->connection->writtenStatements)->toBeEmpty();
});

it('records an absence for a managed person whose rights the actor covers', function (): void {
    $resolver = FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->subject->getKey()]);
    ($this->steering)(true);

    $delegation = app(CreateAbsenceDelegationAction::class)->execute($this->actor, ($this->input)());

    expect($delegation->user_id)->toBe((string) $this->subject->getKey())
        ->and($delegation->delegate_id)->toBe($this->delegateId)
        ->and($resolver->askedFor)->toBe(['absences.manage'])
        ->and($this->connection->writtenStatements)->not->toBeEmpty();
});

it('lets a person record their own absence without asking for any management reach', function (): void {
    $resolver = FakeManagedUserResolver::reachingTeams([])->managing([]);
    ($this->steering)(false);

    app(CreateAbsenceDelegationAction::class)->execute($this->actor, ($this->input)([
        'userId' => (string) $this->actor->getKey(),
    ]));

    expect($resolver->askedFor)->toBeEmpty()
        ->and($this->connection->writtenStatements)->not->toBeEmpty();
});

it('refuses to switch the delegate of another person to the actor', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->subject->getKey()]);
    ($this->steering)(true);

    $errors = ($this->errorsOf)(fn () => app(UpdateAbsenceDelegationAction::class)->execute($this->actor, ($this->delegation)(), ($this->input)([
        'delegateId' => (string) $this->actor->getKey(),
    ])));

    expect($errors)->toHaveKey('delegateId')
        ->and($this->connection->writtenStatements)->toBeEmpty();
});

it('refuses to change the absence of a person outside the management reach of the actor', function (): void {
    FakeManagedUserResolver::reachingTeams([])->managing([]);
    ($this->steering)(true);

    expect(fn () => app(UpdateAbsenceDelegationAction::class)->execute($this->actor, ($this->delegation)(), ($this->input)()))
        ->toThrow(AuthorizationException::class)
        ->and($this->connection->writtenStatements)->toBeEmpty();
});

it('refuses to change the absence of a person who holds more rights than the actor', function (): void {
    FakeManagedUserResolver::reachingTheTenant()->managing([(string) $this->subject->getKey()]);
    ($this->steering)(false);

    expect(fn () => app(UpdateAbsenceDelegationAction::class)->execute($this->actor, ($this->delegation)(), ($this->input)()))
        ->toThrow(AuthorizationException::class)
        ->and($this->connection->writtenStatements)->toBeEmpty();
});
