<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Scim\CreateScimUserAction;
use App\Actions\Scim\DeleteScimUserAction;
use App\Actions\Scim\UpdateScimUserAction;
use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\Actions\Users\DeleteUserAction;
use App\Actions\Users\ReleaseUnverifiedRegistrationAction;
use App\Actions\Users\RestoreUserAction;
use App\Actions\Users\SetUserStatusAction;
use App\DTOs\Scim\ScimFilter;
use App\DTOs\Scim\ScimUserChanges;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Scim\ScimErrorType;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\RegistrationReleaseReason;
use App\Enums\Users\UserStatus;
use App\Exceptions\Scim\ScimException;
use App\Exceptions\Sso\IdentityLinkedToAnotherUserException;
use App\Http\Controllers\Scim\ScimUsersController;
use App\Http\Resources\Scim\ScimUserResource;
use App\Models\Role;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\SsoVerifiedDomain;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\PermissionSubsetGuard;
use App\Support\Scim\ScimUserDirectory;
use App\Support\Sso\SsoConnectionLookup;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    Carbon::setTestNow(Carbon::parse('2026-10-30 12:00:00', 'UTC'));

    $this->tenant = AccessContext::tenant('acme');
    AccessContext::grant('sso.manage');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->creator = ModelStub::make(User::class, ['id' => ModelStub::ulid('helpdesk'), 'tenant_id' => $this->tenantId, 'status' => UserStatus::Accepted->value]);
    $this->token = ModelStub::make(ScimToken::class, ['id' => ModelStub::ulid('token'), 'tenant_id' => $this->tenantId, 'created_by_id' => ModelStub::ulid('helpdesk')], ['creator' => $this->creator]);

    $this->subsetGuard = Mockery::mock(PermissionSubsetGuard::class);
    $this->subsetGuard->shouldReceive('maySteerAccount')->byDefault()->andReturnTrue();
    $this->subsetGuard->shouldReceive('mayAssignRole')->byDefault()->andReturnTrue();
    app()->instance(PermissionSubsetGuard::class, $this->subsetGuard);

    $this->memberRole = ModelStub::make(Role::class, ['id' => ModelStub::ulid('member'), 'tenant_id' => $this->tenantId]);
    app()->instance(AuthorizationDirectory::class, (new FakeAuthorizationDirectory)->withRoles([$this->memberRole]));

    $this->ssoConnection = ModelStub::make(SsoConnection::class, ['tenant_id' => $this->tenantId, 'default_role_id' => ModelStub::ulid('member'), 'allowed_domains' => ['acme.de', 'partner.de']], ['verifiedDomains' => ModelStub::collection(SsoVerifiedDomain::class, [['domain' => 'acme.de']])]);
    $this->lookup = Mockery::mock(SsoConnectionLookup::class);
    $this->lookup->shouldReceive('forTenant')->byDefault()->with($this->tenantId)->andReturnUsing(fn (): ?SsoConnection => $this->ssoConnection);
    app()->instance(SsoConnectionLookup::class, $this->lookup);

    $this->user = fn (array $attributes = [], array $relations = []): User => ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('ada'),
        'tenant_id' => $this->tenantId,
        'email' => 'ada@acme.de',
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'status' => UserStatus::Accepted->value,
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-02 09:30:00',
        ...$attributes,
    ], [
        'identities' => ModelStub::collection(UserIdentity::class, [['provider' => 'scim', 'issuer' => 'scim', 'subject' => '00u-ada']]),
        'teams' => ModelStub::collection(Team::class, [['id' => ModelStub::ulid('sales'), 'name' => 'Vertrieb']]),
        ...$relations,
    ]);

    $this->owner = function (): User {
        $owner = ($this->user)();
        $owner->escalated = true;

        return $owner;
    };

    $this->request = function (string $method = 'GET', array $query = [], ?array $body = null, string $contentType = 'application/scim+json'): Request {
        $request = Request::create('/scim/v2/Users', $method, $query, [], [], ['CONTENT_TYPE' => $contentType], $body === null ? null : json_encode($body));
        $request->attributes->set('scim_token', $this->token);

        return $request;
    };

    $this->directory = Mockery::mock(ScimUserDirectory::class);
    app()->instance(ScimUserDirectory::class, $this->directory);

    $this->release = Mockery::mock(ReleaseUnverifiedRegistrationAction::class);
    $this->release->shouldReceive('execute')->byDefault()->andReturnFalse();
    app()->instance(ReleaseUnverifiedRegistrationAction::class, $this->release);

    $this->scimError = static function (Closure $call): ?array {
        try {
            $call();
        } catch (ScimException $exception) {
            return [$exception->status, $exception->scimType];
        }

        return null;
    };
});

afterEach(function (): void {
    Carbon::setTestNow();
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('presents a user as a scim resource with meta, etag and its teams as groups', function (): void {
    $resource = (new ScimUserResource(($this->user)()))->resolve();

    expect($resource)->toMatchArray([
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
        'id' => ModelStub::ulid('ada'),
        'externalId' => '00u-ada',
        'userName' => 'ada@acme.de',
        'displayName' => 'Ada Lovelace',
        'name' => ['givenName' => 'Ada', 'familyName' => 'Lovelace', 'formatted' => 'Ada Lovelace'],
        'emails' => [['value' => 'ada@acme.de', 'type' => 'work', 'primary' => true]],
        'active' => true,
        'groups' => [['value' => ModelStub::ulid('sales'), 'display' => 'Vertrieb', '$ref' => url('/scim/v2/Groups/'.ModelStub::ulid('sales'))]],
    ])
        ->and($resource['meta']['resourceType'])->toBe('User')
        ->and($resource['meta']['created'])->toBe('2026-10-01T08:00:00+00:00')
        ->and($resource['meta']['lastModified'])->toBe('2026-10-02T09:30:00+00:00')
        ->and($resource['meta']['location'])->toBe(url('/scim/v2/Users/'.ModelStub::ulid('ada')))
        ->and($resource['meta']['version'])->toStartWith('W/"');
});

it('presents a blocked user as inactive and leaves the external id out when none is linked', function (): void {
    $resource = (new ScimUserResource(($this->user)(['status' => UserStatus::Blocked->value], ['identities' => new Collection])))->resolve();

    expect($resource['active'])->toBeFalse()
        ->and($resource)->not->toHaveKey('externalId');
});

it('lists the users of the token tenant page by page with the parsed filter', function (): void {
    $this->directory->shouldReceive('page')->once()
        ->withArgs(fn (string $tenantId, ?ScimFilter $filter, int $offset, int $limit): bool => $tenantId === $this->tenantId
            && $filter?->attribute === 'username'
            && $filter->value === 'ada@acme.de'
            && $offset === 1
            && $limit === 1)
        ->andReturn([3, new Collection([($this->user)()])]);

    $response = app(ScimUsersController::class)->index(($this->request)('GET', ['filter' => 'userName eq "ada@acme.de"', 'startIndex' => '2', 'count' => '1']));
    $body = $response->getData(true);

    expect($response->headers->get('Content-Type'))->toContain('application/scim+json')
        ->and($body['schemas'])->toBe(['urn:ietf:params:scim:api:messages:2.0:ListResponse'])
        ->and($body['totalResults'])->toBe(3)
        ->and($body['startIndex'])->toBe(2)
        ->and($body['itemsPerPage'])->toBe(1)
        ->and($body['Resources'][0]['userName'])->toBe('ada@acme.de');
});

it('caps the page size and treats a start index below one as one', function (): void {
    config(['sso.scim.max_page_size' => 200]);
    $this->directory->shouldReceive('page')->once()->with($this->tenantId, null, 0, 200)->andReturn([0, new Collection]);

    app(ScimUsersController::class)->index(($this->request)('GET', ['startIndex' => '-4', 'count' => '5000']));
});

it('refuses an unsupported filter with 400 invalidFilter', function (): void {
    $this->directory->shouldNotReceive('page');

    expect(($this->scimError)(fn () => app(ScimUsersController::class)->index(($this->request)('GET', ['filter' => 'title eq "CTO"']))))->toBe([400, ScimErrorType::InvalidFilter]);
});

it('refuses a request without a resolved scim token before it reads anything', function (): void {
    $this->directory->shouldNotReceive('page');
    $request = Request::create('/scim/v2/Users');

    expect(($this->scimError)(fn () => app(ScimUsersController::class)->index($request)))->toBe([401, null]);
});

it('looks a single user up only within the tenant of the token and answers 404 for a user of another tenant', function (): void {
    $this->directory->shouldReceive('find')->once()->with($this->tenantId, 'foreign-user')->andReturnNull();

    expect(($this->scimError)(fn () => app(ScimUsersController::class)->show(($this->request)(), 'foreign-user')))->toBe([404, null]);
});

it('answers a single user with its etag', function (): void {
    $this->directory->shouldReceive('find')->andReturn(($this->user)());

    $response = app(ScimUsersController::class)->show(($this->request)(), ModelStub::ulid('ada'));

    expect($response->headers->get('ETag'))->toStartWith('W/"')
        ->and($response->getData(true)['id'])->toBe(ModelStub::ulid('ada'));
});

it('creates a user from the posted resource and answers 201 with its location', function (): void {
    $create = Mockery::mock(CreateScimUserAction::class);
    $create->shouldReceive('execute')->once()
        ->withArgs(fn (ScimToken $token, ScimUserChanges $changes): bool => $token === $this->token && $changes->values['email'] === 'ada@acme.de')
        ->andReturn(($this->user)());
    app()->instance(CreateScimUserAction::class, $create);
    $this->directory->shouldReceive('find')->once()->with($this->tenantId, ModelStub::ulid('ada'))->andReturn(($this->user)());

    $response = app(ScimUsersController::class)->store(($this->request)('POST', [], ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'], 'userName' => 'ada@acme.de']));

    expect($response->getStatusCode())->toBe(201)
        ->and($response->headers->get('Location'))->toBe(url('/scim/v2/Users/'.ModelStub::ulid('ada')));
});

it('refuses a body that is no json with 400 invalidSyntax', function (): void {
    expect(($this->scimError)(fn () => app(ScimUsersController::class)->store(($this->request)('POST', [], ['userName' => 'ada@acme.de'], 'text/plain'))))->toBe([400, ScimErrorType::InvalidSyntax]);
});

it('applies a patch to the user of the tenant and answers with the updated resource', function (): void {
    $user = ($this->user)();
    $this->directory->shouldReceive('find')->twice()->with($this->tenantId, ModelStub::ulid('ada'))->andReturn($user);
    $update = Mockery::mock(UpdateScimUserAction::class);
    $update->shouldReceive('execute')->once()->withArgs(fn (ScimToken $token, User $target, ScimUserChanges $changes): bool => $token === $this->token && $target === $user && $changes->values === ['is_active' => false])->andReturn($user);
    app()->instance(UpdateScimUserAction::class, $update);

    $response = app(ScimUsersController::class)->update(($this->request)('PATCH', [], [
        'schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'],
        'Operations' => [['op' => 'Replace', 'path' => 'active', 'value' => 'False']],
    ]), ModelStub::ulid('ada'));

    expect($response->getStatusCode())->toBe(200);
});

it('removes a user of the tenant and answers 204', function (): void {
    $user = ($this->user)();
    $this->directory->shouldReceive('find')->andReturn($user);
    $delete = Mockery::mock(DeleteScimUserAction::class);
    $delete->shouldReceive('execute')->once()->with($this->token, $user);
    app()->instance(DeleteScimUserAction::class, $delete);

    $response = app(ScimUsersController::class)->destroy(($this->request)('DELETE'), ModelStub::ulid('ada'));

    expect($response->getStatusCode())->toBe(204)
        ->and($response->getContent())->toBe('');
});

it('reads users only within the tenant, never service users and filters by user name or external id', function (): void {
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'count(*)') ? [['aggregate' => 0]] : []);
    $directory = new ScimUserDirectory;

    $byName = QueryShape::of($directory->query('tenant-a', new ScimFilter('username', 'Ada@Acme.de')));
    $byExternalId = QueryShape::of($directory->query('tenant-a', new ScimFilter('externalid', '00u-ada')));
    $single = QueryShape::attemptedBy(static fn () => $directory->find('tenant-a', 'user-1'));

    expect($byName->isScopedToTenant('users', 'tenant-a'))->toBeTrue()
        ->and($byName->sql)->toContain('"users"."is_service" = ?')
        ->and($byName->hasBinding('ada@acme.de'))->toBeTrue()
        ->and($byName->hidesSoftDeleted('users'))->toBeTrue()
        ->and($byExternalId->sql)->toContain('from "user_identities"')
        ->and($byExternalId->isScopedToTenant('user_identities', 'tenant-a'))->toBeTrue()
        ->and($byExternalId->hasBinding('scim'))->toBeTrue()
        ->and($byExternalId->hasBinding('00u-ada'))->toBeTrue()
        ->and($single)->toBeNull();
});

it('creates a scim user with the default role of the connection and links its external id', function (): void {
    StaticQueryConnection::install(static fn (): array => []);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $created = ($this->user)();
    $provision->shouldReceive('execute')->once()->withArgs(fn (string $tenantId, ProvisionedUserData $data): bool => $tenantId === $this->tenantId
        && $data->email === 'ada@acme.de'
        && !$data->isActive
        && $data->roleId === ModelStub::ulid('member'))->andReturn($created);
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->with($created, IdentityProvider::Scim, 'scim', '00u-ada')->andReturn(new UserIdentity);
    app()->instance(ProvisionIdentityUserAction::class, $provision);
    app()->instance(LinkUserIdentityAction::class, $link);

    $user = app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'first_name' => 'Ada', 'last_name' => null, 'is_active' => false, 'external_id' => '00u-ada']));

    expect($user)->toBe($created);
});

it('answers 409 instead of creating a user whose external id is linked to another user', function (): void {
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldReceive('execute')->once()->andReturn(($this->user)());
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->andThrow(new IdentityLinkedToAnotherUserException(IdentityProvider::Scim));
    app()->instance(ProvisionIdentityUserAction::class, $provision);
    app()->instance(LinkUserIdentityAction::class, $link);

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true, 'external_id' => '00u-grace']))))->toBe([409, ScimErrorType::Uniqueness]);
});

it('refuses to create a user whose email address is already taken', function (): void {
    $connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "users"') ? [($this->user)()->getAttributes()] : []);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]))))->toBe([409, ScimErrorType::Uniqueness])
        ->and($connection->queries[0]['sql'])->not->toContain('"deleted_at" is null')
        ->and($connection->queries[0]['bindings'])->toContain('ada@acme.de');
});

it('restores a removed user of the same tenant on a repeated provisioning instead of answering 409', function (bool $isActive, UserStatus $status): void {
    $removed = ($this->user)(['status' => UserStatus::Deleted->value, 'first_name' => 'Old', 'deleted_at' => '2026-10-20 08:00:00']);
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"') ? [$removed->getAttributes()] : [], static fn (): int => 1);
    $restore = Mockery::mock(RestoreUserAction::class);
    $restore->shouldReceive('execute')->once()->withArgs(fn (User $user, UserStatus $restoredStatus): bool => $user->getKey() === $removed->getKey() && $restoredStatus === $status);
    $roles = Mockery::mock(SyncUserRolesAction::class);
    $roles->shouldReceive('execute')->once()->withArgs(fn (User $user, array $roleIds): bool => $user->getKey() === $removed->getKey() && $roleIds === [ModelStub::ulid('member')]);
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->withArgs(fn (User $user, IdentityProvider $provider, string $issuer, string $subject): bool => $user->getKey() === $removed->getKey() && $subject === '00u-ada')->andReturn(new UserIdentity);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(RestoreUserAction::class, $restore);
    app()->instance(SyncUserRolesAction::class, $roles);
    app()->instance(LinkUserIdentityAction::class, $link);
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    $user = app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'first_name' => 'Ada', 'is_active' => $isActive, 'external_id' => '00u-ada']));

    expect($user->getKey())->toBe($removed->getKey())
        ->and($user->first_name)->toBe('Ada')
        ->and(collect($connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('update "users" set "first_name" = ?');
})->with([
    'active' => [true, UserStatus::Accepted],
    'inactive' => [false, UserStatus::Blocked],
]);

it('never restores a removed account of another tenant or an active account and answers 409', function (array $attributes): void {
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "users"') ? [($this->user)($attributes)->getAttributes()] : []);
    $restore = Mockery::mock(RestoreUserAction::class);
    $restore->shouldNotReceive('execute');
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(RestoreUserAction::class, $restore);
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]))))->toBe([409, ScimErrorType::Uniqueness]);
})->with([
    'active account of the tenant' => [[]],
    'removed account of another tenant' => [['tenant_id' => ModelStub::ulid('globex'), 'deleted_at' => '2026-10-20 08:00:00']],
    'removed service account' => [['is_service' => true, 'deleted_at' => '2026-10-20 08:00:00']],
]);

it('deactivates through the user status action so sessions and tokens of the user end', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $user = ($this->user)();
    $status = Mockery::mock(SetUserStatusAction::class);
    $status->shouldReceive('execute')->once()->with($user, ['status' => UserStatus::Blocked->value]);
    app()->instance(SetUserStatusAction::class, $status);

    app(UpdateScimUserAction::class)->execute($this->token, $user, new ScimUserChanges(['is_active' => false, 'last_name' => 'Byron']));

    expect(collect($connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('update "users" set "last_name" = ?');
});

it('leaves the status alone while the patch does not change it', function (): void {
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $status = Mockery::mock(SetUserStatusAction::class);
    $status->shouldNotReceive('execute');
    app()->instance(SetUserStatusAction::class, $status);

    app(UpdateScimUserAction::class)->execute($this->token, ($this->user)(), new ScimUserChanges(['is_active' => true]));
});

it('answers 409 and keeps the old identity when the new external id is linked to another user', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->andThrow(new IdentityLinkedToAnotherUserException(IdentityProvider::Scim));
    app()->instance(LinkUserIdentityAction::class, $link);

    expect(($this->scimError)(fn () => app(UpdateScimUserAction::class)->execute($this->token, ($this->user)(), new ScimUserChanges(['external_id' => '00u-grace']))))->toBe([409, ScimErrorType::Uniqueness])
        ->and($connection->writtenSqlOf('user_identities'))->toBe([]);
});

it('drops the previous external ids of the user only after the new one is linked and keeps the new one', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $user = ($this->user)();
    $identity = ModelStub::make(UserIdentity::class, ['id' => ModelStub::ulid('new-identity'), 'user_id' => $user->getKey(), 'provider' => 'scim', 'issuer' => 'scim', 'subject' => '00u-ada-2']);
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->with($user, IdentityProvider::Scim, 'scim', '00u-ada-2')->andReturnUsing(function () use ($connection, $identity): UserIdentity {
        expect($connection->writtenSqlOf('user_identities'))->toBe([]);

        return $identity;
    });
    app()->instance(LinkUserIdentityAction::class, $link);

    app(UpdateScimUserAction::class)->execute($this->token, $user, new ScimUserChanges(['external_id' => '00u-ada-2']));

    $removal = collect($connection->writtenStatements)->first(static fn (array $statement): bool => str_contains($statement['sql'], 'user_identities'));

    expect($removal)->not->toBeNull()
        ->and($removal['sql'])->toContain('"id" != ?')
        ->and($removal['bindings'])->toContain(ModelStub::ulid('new-identity'), (string) $user->getKey(), 'scim');
});

it('refuses to move a user onto an email address another account holds', function (): void {
    $holder = ($this->user)(['id' => ModelStub::ulid('grace'), 'email' => 'grace@acme.de']);
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"') ? [$holder->getAttributes()] : []);
    $this->release->shouldReceive('execute')->once()->withArgs(static fn (User $user, RegistrationReleaseReason $reason): bool => $user->getKey() === $holder->getKey() && $reason === RegistrationReleaseReason::ClaimedByScim)->andReturnFalse();

    expect(($this->scimError)(fn () => app(UpdateScimUserAction::class)->execute($this->token, ($this->user)(), new ScimUserChanges(['email' => 'grace@acme.de']))))->toBe([409, ScimErrorType::Uniqueness])
        ->and($connection->queries[0]['sql'])->toContain('"id" != ?')
        ->and($connection->queries[0]['sql'])->not->toContain('"deleted_at" is null')
        ->and($connection->writtenStatements)->toBe([]);
});

it('moves a user onto an address an unverified self registration of another tenant held once that registration is released', function (): void {
    $squatter = ($this->user)(['id' => ModelStub::ulid('squatter'), 'tenant_id' => ModelStub::ulid('globex'), 'email' => 'grace@acme.de']);
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"') ? [$squatter->getAttributes()] : [], static fn (): int => 1);
    $this->release->shouldReceive('execute')->once()->withArgs(static fn (User $user, RegistrationReleaseReason $reason): bool => $user->getKey() === $squatter->getKey() && $reason === RegistrationReleaseReason::ClaimedByScim)->andReturnTrue();
    $user = ($this->user)();

    app(UpdateScimUserAction::class)->execute($this->token, $user, new ScimUserChanges(['email' => 'grace@acme.de']));

    expect($user->email)->toBe('grace@acme.de')
        ->and($connection->writtenSqlOf('users'))->not->toBe([]);
});

it('provisions an address an unverified self registration of another tenant held once that registration is released, never reusing that account', function (): void {
    $squatter = ($this->user)(['id' => ModelStub::ulid('squatter'), 'tenant_id' => ModelStub::ulid('globex')]);
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"') ? [$squatter->getAttributes()] : []);
    $this->release->shouldReceive('execute')->once()->withArgs(static fn (User $user, RegistrationReleaseReason $reason): bool => $user->getKey() === $squatter->getKey() && $reason === RegistrationReleaseReason::ClaimedByScim)->andReturnTrue();
    $created = ($this->user)(['id' => ModelStub::ulid('provisioned')]);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldReceive('execute')->once()->andReturn($created);
    $restore = Mockery::mock(RestoreUserAction::class);
    $restore->shouldNotReceive('execute');
    app()->instance(ProvisionIdentityUserAction::class, $provision);
    app()->instance(RestoreUserAction::class, $restore);

    expect(app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true])))->toBe($created);
});

it('removes a user through the user deletion that ends its sessions and tokens', function (): void {
    $user = ($this->user)();
    $delete = Mockery::mock(DeleteUserAction::class);
    $delete->shouldReceive('execute')->once()->with($user);
    app()->instance(DeleteUserAction::class, $delete);

    app(DeleteScimUserAction::class)->execute($this->token, $user);
});

it('refuses to change an owner or super admin account through scim before anything is written', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $status = Mockery::mock(SetUserStatusAction::class);
    $status->shouldNotReceive('execute');
    app()->instance(SetUserStatusAction::class, $status);

    expect(($this->scimError)(fn () => app(UpdateScimUserAction::class)->execute($this->token, ($this->owner)(), new ScimUserChanges(['email' => 'mallory@acme.de', 'is_active' => false]))))->toBe([400, ScimErrorType::Mutability])
        ->and($connection->writtenStatements)->toBe([]);
});

it('refuses to remove an owner or super admin account through scim', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $delete = Mockery::mock(DeleteUserAction::class);
    $delete->shouldNotReceive('execute');
    app()->instance(DeleteUserAction::class, $delete);

    expect(($this->scimError)(fn () => app(DeleteScimUserAction::class)->execute($this->token, ($this->owner)())))->toBe([400, ScimErrorType::Mutability])
        ->and($connection->writtenStatements)->toBe([]);
});

it('refuses to move an account beyond the rights of the token creator onto a new email before anything is written', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $admin = ($this->user)();
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->creator, $admin)->andReturnFalse();

    expect(($this->scimError)(fn () => app(UpdateScimUserAction::class)->execute($this->token, $admin, new ScimUserChanges(['email' => 'mallory@acme.de']))))->toBe([400, ScimErrorType::Mutability])
        ->and($connection->writtenStatements)->toBe([]);
});

it('refuses to remove an account beyond the rights of the token creator', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $admin = ($this->user)();
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->with($this->creator, $admin)->andReturnFalse();
    $delete = Mockery::mock(DeleteUserAction::class);
    $delete->shouldNotReceive('execute');
    app()->instance(DeleteUserAction::class, $delete);

    expect(($this->scimError)(fn () => app(DeleteScimUserAction::class)->execute($this->token, $admin)))->toBe([400, ScimErrorType::Mutability])
        ->and($connection->writtenStatements)->toBe([]);
});

it('names the protected owner account in the scim error detail in german', function (): void {
    expect(__('i18n.backend.exceptions.scim.scim_exception.privileged_account'))->toBe('Konten mit Inhaber- oder Super-Admin-Rolle werden nicht über SCIM verwaltet.');
});

it('refuses to provision or restore a user while the token has no entitled creator, before anything is read or written', function (?User $creator): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(ProvisionIdentityUserAction::class, $provision);
    $token = ModelStub::make(ScimToken::class, ['id' => ModelStub::ulid('orphan-token'), 'tenant_id' => $this->tenantId], ['creator' => $creator]);

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]))))->toBe([400, ScimErrorType::Mutability])
        ->and($connection->queries)->toBe([])
        ->and($connection->writtenStatements)->toBe([]);
})->with([
    'no creator' => [null],
    'blocked creator' => fn (): User => ModelStub::make(User::class, ['id' => ModelStub::ulid('blocked'), 'tenant_id' => $this->tenantId, 'status' => UserStatus::Blocked->value]),
]);

it('refuses to provision a user with a default role beyond the rights of the token creator', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $this->subsetGuard->shouldReceive('mayAssignRole')->once()->with($this->creator, $this->memberRole)->andReturnFalse();
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    try {
        app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]));
        $this->fail('The provisioning was not refused.');
    } catch (ScimException $exception) {
        expect([$exception->status, $exception->scimType, $exception->getMessage()])->toBe([400, ScimErrorType::Mutability, __('i18n.backend.exceptions.scim.scim_exception.default_role_beyond_token_creator')])
            ->and($connection->writtenStatements)->toBe([]);
    }
});

it('refuses to provision an address outside the proven single sign-on domains with 400 invalidValue', function (string $email, bool $hasConnection): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $this->ssoConnection = $hasConnection ? $this->ssoConnection : null;
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    try {
        app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => $email, 'is_active' => true]));
        $this->fail('The provisioning was not refused.');
    } catch (ScimException $exception) {
        expect([$exception->status, $exception->scimType, $exception->getMessage()])->toBe([400, ScimErrorType::InvalidValue, __('i18n.backend.exceptions.scim.scim_exception.email_outside_sso_domains')])
            ->and($connection->writtenStatements)->toBe([]);
    }
})->with([
    'foreign domain' => ['mallory@evil.test', true],
    'listed but unproven domain' => ['ada@partner.de', true],
    'no connection' => ['ada@acme.de', false],
]);

it('refuses to restore a removed account beyond the rights of the token creator', function (): void {
    $removed = ($this->user)(['status' => UserStatus::Deleted->value, 'deleted_at' => '2026-10-20 08:00:00']);
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"') ? [$removed->getAttributes()] : [], static fn (): int => 1);
    $this->subsetGuard->shouldReceive('maySteerAccount')->once()->withArgs(fn (User $creator, User $target): bool => $creator === $this->creator && $target->getKey() === $removed->getKey())->andReturnFalse();
    $restore = Mockery::mock(RestoreUserAction::class);
    $restore->shouldNotReceive('execute');
    app()->instance(RestoreUserAction::class, $restore);

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($this->token, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]))))->toBe([400, ScimErrorType::Mutability])
        ->and($connection->writtenStatements)->toBe([]);
});

it('refuses to move a user onto an address outside the proven single sign-on domains', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    expect(($this->scimError)(fn () => app(UpdateScimUserAction::class)->execute($this->token, ($this->user)(), new ScimUserChanges(['email' => 'ada@evil.test']))))->toBe([400, ScimErrorType::InvalidValue])
        ->and($connection->writtenStatements)->toBe([]);
});

it('marks an address scim moves onto a proven domain as verified now and keeps the verification while the address stays', function (array $values, bool $isReset): void {
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'exists') ? [['exists' => false]] : [], static fn (): int => 1);
    $user = ($this->user)(['email_verified_at' => '2026-10-01 08:00:00']);

    app(UpdateScimUserAction::class)->execute($this->token, $user, new ScimUserChanges($values));

    $update = collect($connection->writtenStatements)->pluck('sql')->implode(' ');

    expect($user->email_verified_at?->toIso8601String())->toBe($isReset ? '2026-10-30T12:00:00+00:00' : '2026-10-01T08:00:00+00:00')
        ->and(str_contains($update, '"email_verified_at" = ?'))->toBe($isReset);
})->with([
    'new address' => [['email' => 'ada.lovelace@acme.de'], true],
    'same address in another case' => [['email' => 'ADA@acme.de', 'last_name' => 'Byron'], false],
]);
