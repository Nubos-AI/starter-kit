<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Actions\Tenancy\PurgeTenantAction;
use App\Actions\Users\AcceptInvitationAction;
use App\Actions\Users\InviteUsersAction;
use App\Actions\Users\IssueInvitationAction;
use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\Actions\Users\RestoreUserAction;
use App\DTOs\Tenancy\PurgeReport;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\RoleInputRules;
use App\Support\Maintenance\MaintenanceLockRegistry;
use App\Support\Modules\AllowTenantDeletion;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Users\EmailListParser;
use App\Support\Users\PendingInvitationResolver;
use App\Support\Users\ServiceAccountEmail;
use App\Support\Users\UnverifiedRegistrationDirectory;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->token = str_repeat('b', 64);
    $this->address = 'invited@example.test';

    $this->invited = AccessContext::user($this->tenant, [
        'email' => $this->address,
        'status' => UserStatus::Invited->value,
    ], 'invitation-outcome-user');

    /** @var array<string, mixed> */
    $this->acceptance = [
        'salutation' => Salutation::Mix->value,
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'password' => 'Str0ng-Passphrase!42',
        'password_confirmation' => 'Str0ng-Passphrase!42',
    ];

    /** @var callable(array<string, mixed>|null):array<string, mixed> */
    $this->userRow = fn (array $overrides = []): array => [
        'id' => (string) $this->invited->getKey(),
        'tenant_id' => (string) $this->tenant->getKey(),
        'email' => $this->address,
        'status' => UserStatus::Invited->value,
        'invitation_token_hash' => hash('sha256', $this->token),
        'invitation_expires_at' => now()->addDay()->toDateTimeString(),
        'deleted_at' => null,
        ...$overrides,
    ];

    /** @var callable(list<array<string, mixed>>, int):StaticQueryConnection */
    $this->answering = static fn (array $rows, int $affected = 1): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => str_contains($sql, 'from "users"') ? $rows : [],
        static fn (): int => $affected,
    );

    $this->actingUser = AccessContext::user($this->tenant, [], 'inviting-actor');

    $this->ssoConnection = null;
    $this->lookup = Mockery::mock(SsoConnectionLookup::class);
    $this->lookup->shouldReceive('forTenant')->byDefault()->andReturnUsing(fn (): ?SsoConnection => $this->ssoConnection);

    $this->purge = Mockery::mock(PurgeTenantAction::class);
    $this->registrations = Mockery::mock(UnverifiedRegistrationDirectory::class);
    $this->registrations->shouldReceive('isSoleMemberOfTenant')->byDefault()->andReturnTrue();
    $locks = Mockery::mock(MaintenanceLockRegistry::class);
    $locks->shouldReceive('activeFor')->andReturnNull();
    $this->release = new ReleaseUnverifiedRegistrationAction($this->registrations, new AllowTenantDeletion, $locks, $this->purge);

    /** @var callable(IssueInvitationAction):InviteUsersAction */
    $this->inviteWith = fn (IssueInvitationAction $issue): InviteUsersAction => new InviteUsersAction(
        new EmailListParser(new ServiceAccountEmail),
        $issue,
        Mockery::mock(SyncUserRolesAction::class)->shouldIgnoreMissing(),
        Mockery::mock(SyncTeamMembersAction::class)->shouldIgnoreMissing(),
        app(RoleInputRules::class),
        app(RestoreUserAction::class),
        $this->lookup,
        $this->release,
    );

    $this->claimingConnection = fn (array $verifiedDomains): SsoConnection => ModelStub::make(SsoConnection::class, [
        'tenant_id' => (string) $this->tenant->getKey(),
        'allowed_domains' => ['example.test'],
    ], ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, array_map(static fn (string $domain): array => ['domain' => $domain], $verifiedDomains))]);

    $this->foreignRegistration = fn (array $overrides = []): array => ($this->userRow)([
        'id' => ModelStub::ulid('squatter'),
        'tenant_id' => ModelStub::ulid('squatter-tenant'),
        'status' => UserStatus::Accepted->value,
        'invitation_token_hash' => null,
        'invitation_expires_at' => null,
        'email_verified_at' => null,
        'invited_by_id' => null,
        'is_service' => false,
        ...$overrides,
    ]);

    $this->answeringWithTenant = static fn (array $userRow): StaticQueryConnection => StaticQueryConnection::install(
        static fn (string $sql): array => match (true) {
            str_contains($sql, 'from "users"') => [$userRow],
            str_contains($sql, 'from "tenants"') => [['id' => ModelStub::ulid('squatter-tenant'), 'name' => 'Squatter']],
            default => [],
        },
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('sends an invitation token that names no pending invitation into the not found path', function (Closure $rows): void {
    ($this->answering)($rows($this->userRow));

    expect(fn (): User => app(PendingInvitationResolver::class)->resolveOrFail($this->token))
        ->toThrow(NotFoundHttpException::class);
})->with([
    'unknown token' => [fn (Closure $userRow): array => []],
    'expired invitation' => [fn (Closure $userRow): array => [$userRow(['invitation_expires_at' => now()->subMinute()->toDateTimeString()])]],
    'invitation without a deadline' => [fn (Closure $userRow): array => [$userRow(['invitation_expires_at' => null])]],
]);

it('hands back the still pending invitation it found', function (): void {
    ($this->answering)([($this->userRow)()]);

    $resolved = app(PendingInvitationResolver::class)->resolveOrFail($this->token);

    expect((string) $resolved->getKey())->toBe((string) $this->invited->getKey());
});

it('refuses an acceptance whose conditional update touched no row any more', function (): void {
    ($this->answering)([($this->userRow)()], 0);

    expect(fn () => app(AcceptInvitationAction::class)->execute($this->invited, $this->acceptance))
        ->toThrow(InvitationNotAcceptableException::class);
});

it('lets an acceptance through that consumed exactly its own invitation', function (): void {
    $connection = ($this->answering)([($this->userRow)(['status' => UserStatus::Accepted->value, 'invitation_token_hash' => null])], 1);

    app(AcceptInvitationAction::class)->execute($this->invited, $this->acceptance);

    expect($this->invited->status)->toBe(UserStatus::Accepted)
        ->and($connection->writtenSqlOf('users'))->toHaveCount(1);
});

it('skips an address that already belongs to another tenant, live, invited or removed, exactly like a settled own account and writes nothing', function (array $overrides): void {
    $foreignRow = ($this->userRow)([
        'id' => ModelStub::ulid('foreign-user'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
        'status' => UserStatus::Accepted->value,
        ...$overrides,
    ]);

    $connection = ($this->answering)([$foreignRow]);
    $spy = GateSpy::allowing('resendInvitation');

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->skipped)->toBe([$this->address])
        ->and($result->invited)->toBe([])
        ->and($result->renewed)->toBe([])
        ->and($spy->calls)->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'live account' => [[]],
    'invited account' => [['status' => UserStatus::Invited->value]],
    'removed account' => [['status' => UserStatus::Deleted->value, 'deleted_at' => '2026-10-20 08:00:00']],
]);

it('skips an address that already belongs to a settled user of the own tenant', function (): void {
    $connection = ($this->answering)([($this->userRow)(['status' => UserStatus::Accepted->value])]);

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->skipped)->toBe([$this->address])
        ->and($result->invited)->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
});

it('renews an address whose user of the own tenant is still merely invited', function (): void {
    ($this->answering)([($this->userRow)()]);
    $spy = GateSpy::allowing('resendInvitation');

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldReceive('execute')->once();

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->renewed)->toBe([$this->address])
        ->and($result->invited)->toBe([])
        ->and($spy->abilities())->toBe(['resendInvitation'])
        ->and($spy->calls[0]['arguments'][0]->getKey())->toBe((string) $this->invited->getKey());
});

it('skips an invited or removed address of the own tenant the acting user may not re-invite and neither restores the account nor mails a new invitation', function (array $overrides): void {
    $connection = ($this->answering)([($this->userRow)($overrides)]);
    $spy = GateSpy::allowing();

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->skipped)->toBe([$this->address])
        ->and($result->renewed)->toBe([])
        ->and($spy->abilities())->toBe(['resendInvitation'])
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'still invited' => [[]],
    'removed' => [['status' => UserStatus::Deleted->value, 'deleted_at' => '2026-10-20 08:00:00']],
]);

it('releases an unverified self registration of another tenant on a proven domain of the inviting tenant and invites the address as a new account', function (): void {
    $this->ssoConnection = ($this->claimingConnection)(['example.test']);
    $connection = ($this->answeringWithTenant)(($this->foreignRegistration)());
    $this->lookup->shouldReceive('forTenant')->once()->with((string) $this->tenant->getKey())->andReturnUsing(fn (): SsoConnection => $this->ssoConnection);
    $this->purge->shouldReceive('execute')->once()->withArgs(static fn (Tenant $tenant): bool => $tenant->getKey() === ModelStub::ulid('squatter-tenant'))->andReturn(new PurgeReport([], 0, 0, []));

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldReceive('execute')->once()->withArgs(fn (User $user): bool => $user->getKey() !== ModelStub::ulid('squatter') && $user->tenant_id === (string) $this->tenant->getKey() && $user->status === UserStatus::Invited);

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->invited)->toBe([$this->address])
        ->and($result->skipped)->toBe([])
        ->and(collect($connection->writtenStatements)->pluck('sql')->first(static fn (string $sql): bool => str_starts_with($sql, 'insert into "users"')))->not->toBeNull()
        ->and(collect($connection->writtenStatements)->pluck('sql')->filter(static fn (string $sql): bool => str_starts_with($sql, 'update "users"') && str_contains($sql, '"tenant_id"'))->all())->toBe([]);
});

it('keeps skipping a foreign unverified registration while the inviting tenant has not proven the domain of the address', function (?array $verifiedDomains): void {
    $this->ssoConnection = $verifiedDomains === null ? null : ($this->claimingConnection)($verifiedDomains);
    $connection = ($this->answeringWithTenant)(($this->foreignRegistration)());
    $this->lookup->shouldReceive('forTenant')->with((string) $this->tenant->getKey())->andReturnUsing(fn (): ?SsoConnection => $this->ssoConnection);
    $this->lookup->shouldNotReceive('forTenant')->with(ModelStub::ulid('squatter-tenant'));
    $this->purge->shouldNotReceive('execute');

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->skipped)->toBe([$this->address])
        ->and($result->invited)->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'no single sign-on connection' => [null],
    'domain allowed but not proven' => [[]],
    'only another domain proven' => [['other.test']],
]);

it('never releases a foreign account on a proven domain that verified its address, was invited or serves the api', function (array $overrides): void {
    $this->ssoConnection = ($this->claimingConnection)(['example.test']);
    $connection = ($this->answeringWithTenant)(($this->foreignRegistration)($overrides));
    $this->purge->shouldNotReceive('execute');

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->skipped)->toBe([$this->address])
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'verified address' => [['email_verified_at' => '2026-10-01 08:00:00']],
    'pending invitation' => [['status' => UserStatus::Invited->value, 'invited_by_id' => ModelStub::ulid('foreign-admin')]],
    'accepted invitation' => [['invited_by_id' => ModelStub::ulid('foreign-admin')]],
    'service account' => [['is_service' => true]],
]);

it('never releases a foreign registration that shares its tenant with further accounts', function (): void {
    $this->ssoConnection = ($this->claimingConnection)(['example.test']);
    ($this->answeringWithTenant)(($this->foreignRegistration)());
    $this->registrations->shouldReceive('isSoleMemberOfTenant')->andReturnFalse();
    $this->purge->shouldNotReceive('execute');

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->skipped)->toBe([$this->address]);
});
