<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Teams\SyncTeamMembersAction;
use App\Actions\Users\AcceptInvitationAction;
use App\Actions\Users\InviteUsersAction;
use App\Actions\Users\IssueInvitationAction;
use App\DTOs\Users\InvitationResultData;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Exceptions\Users\InvitationNotAcceptableException;
use App\Models\User;
use App\Support\Authorization\RoleInputRules;
use App\Support\Users\EmailListParser;
use App\Support\Users\PendingInvitationResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
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

    /** @var callable(IssueInvitationAction):InviteUsersAction */
    $this->inviteWith = static fn (IssueInvitationAction $issue): InviteUsersAction => new InviteUsersAction(
        new EmailListParser,
        $issue,
        Mockery::mock(SyncUserRolesAction::class)->shouldIgnoreMissing(),
        Mockery::mock(SyncTeamMembersAction::class)->shouldIgnoreMissing(),
        app(RoleInputRules::class),
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('sends an unknown invitation token into the not found path', function (): void {
    ($this->answering)([]);

    expect(fn (): User => app(PendingInvitationResolver::class)->resolveOrFail($this->token))
        ->toThrow(NotFoundHttpException::class);
});

it('sends an expired invitation token into the not found path', function (): void {
    ($this->answering)([($this->userRow)(['invitation_expires_at' => now()->subMinute()->toDateTimeString()])]);

    expect(fn (): User => app(PendingInvitationResolver::class)->resolveOrFail($this->token))
        ->toThrow(NotFoundHttpException::class);
});

it('sends an invitation without a deadline into the not found path', function (): void {
    ($this->answering)([($this->userRow)(['invitation_expires_at' => null])]);

    expect(fn (): User => app(PendingInvitationResolver::class)->resolveOrFail($this->token))
        ->toThrow(NotFoundHttpException::class);
});

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

it('counts an address that belongs to another tenant as freshly invited and writes nothing', function (): void {
    $foreignRow = ($this->userRow)([
        'id' => ModelStub::ulid('foreign-user'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
        'status' => UserStatus::Accepted->value,
    ]);

    $connection = ($this->answering)([$foreignRow]);

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldNotReceive('execute');

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result)->toBeInstanceOf(InvitationResultData::class)
        ->and($result->invited)->toBe([$this->address])
        ->and($result->renewed)->toBe([])
        ->and($result->skipped)->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
});

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

    $issue = Mockery::mock(IssueInvitationAction::class);
    $issue->shouldReceive('execute')->once();

    $result = ($this->inviteWith)($issue)->execute($this->actingUser, [
        'emails' => $this->address,
        'role_ids' => [],
        'team_ids' => [],
    ]);

    expect($result->renewed)->toBe([$this->address])
        ->and($result->invited)->toBe([]);
});
