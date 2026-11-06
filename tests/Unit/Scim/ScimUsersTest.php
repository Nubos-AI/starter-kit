<?php

declare(strict_types=1);

use App\Actions\Authorization\SyncUserRolesAction;
use App\Actions\Scim\CreateScimUserAction;
use App\Actions\Scim\DeleteScimUserAction;
use App\Actions\Scim\UpdateScimUserAction;
use App\Actions\Sso\LinkUserIdentityAction;
use App\Actions\Sso\ProvisionIdentityUserAction;
use App\Actions\Users\DeleteUserAction;
use App\Actions\Users\RestoreUserAction;
use App\Actions\Users\SetUserStatusAction;
use App\DTOs\Scim\ScimFilter;
use App\DTOs\Scim\ScimUserChanges;
use App\DTOs\Sso\ProvisionedUserData;
use App\Enums\Scim\ScimErrorType;
use App\Enums\Sso\IdentityProvider;
use App\Enums\Users\UserStatus;
use App\Exceptions\Scim\ScimException;
use App\Http\Controllers\Scim\ScimUsersController;
use App\Http\Resources\Scim\ScimUserResource;
use App\Models\ScimToken;
use App\Models\SsoConnection;
use App\Models\Team;
use App\Models\User;
use App\Models\UserIdentity;
use App\Support\Scim\ScimUserDirectory;
use App\Support\Sso\SsoConnectionLookup;
use App\Support\Users\UserSessionInvalidator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\Support\AccessContext;
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
    $this->tenantId = (string) $this->tenant->getKey();
    $this->token = ModelStub::make(ScimToken::class, ['id' => ModelStub::ulid('token'), 'tenant_id' => $this->tenantId]);

    $this->user = fn (array $attributes = [], array $relations = []): User => ModelStub::make(User::class, [
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

    $this->request = function (string $method = 'GET', array $query = [], ?array $body = null, string $contentType = 'application/scim+json'): Request {
        $request = Request::create('/scim/v2/Users', $method, $query, [], [], ['CONTENT_TYPE' => $contentType], $body === null ? null : json_encode($body));
        $request->attributes->set('scim_token', $this->token);

        return $request;
    };

    $this->directory = Mockery::mock(ScimUserDirectory::class);
    app()->instance(ScimUserDirectory::class, $this->directory);

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
        ->withArgs(fn (string $tenantId, ScimUserChanges $changes): bool => $tenantId === $this->tenantId && $changes->values['email'] === 'ada@acme.de')
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
    $update->shouldReceive('execute')->once()->withArgs(static fn (User $target, ScimUserChanges $changes): bool => $target === $user && $changes->values === ['is_active' => false])->andReturn($user);
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
    $delete->shouldReceive('execute')->once()->with($user);
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
    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->with($this->tenantId)->andReturn(ModelStub::make(SsoConnection::class, ['default_role_id' => ModelStub::ulid('member')]));
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $created = ($this->user)();
    $provision->shouldReceive('execute')->once()->withArgs(fn (string $tenantId, ProvisionedUserData $data): bool => $tenantId === $this->tenantId
        && $data->email === 'ada@acme.de'
        && !$data->isActive
        && $data->roleId === ModelStub::ulid('member'))->andReturn($created);
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->with($created, IdentityProvider::Scim, 'scim', '00u-ada')->andReturn(new UserIdentity);
    app()->instance(SsoConnectionLookup::class, $lookup);
    app()->instance(ProvisionIdentityUserAction::class, $provision);
    app()->instance(LinkUserIdentityAction::class, $link);

    $user = app(CreateScimUserAction::class)->execute($this->tenantId, new ScimUserChanges(['email' => 'ada@acme.de', 'first_name' => 'Ada', 'last_name' => null, 'is_active' => false, 'external_id' => '00u-ada']));

    expect($user)->toBe($created);
});

it('refuses to create a user whose email address is already taken', function (): void {
    $connection = StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "users"') ? [($this->user)()->getAttributes()] : []);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($this->tenantId, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]))))->toBe([409, ScimErrorType::Uniqueness])
        ->and($connection->queries[0]['sql'])->not->toContain('"deleted_at" is null')
        ->and($connection->queries[0]['bindings'])->toContain('ada@acme.de');
});

it('restores a removed user of the same tenant on a repeated provisioning instead of answering 409', function (bool $isActive, UserStatus $status): void {
    $removed = ($this->user)(['status' => UserStatus::Deleted->value, 'first_name' => 'Old', 'deleted_at' => '2026-10-20 08:00:00']);
    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "users"') ? [$removed->getAttributes()] : [], static fn (): int => 1);
    $lookup = Mockery::mock(SsoConnectionLookup::class);
    $lookup->shouldReceive('forTenant')->with($this->tenantId)->andReturn(ModelStub::make(SsoConnection::class, ['default_role_id' => ModelStub::ulid('member')]));
    $restore = Mockery::mock(RestoreUserAction::class);
    $restore->shouldReceive('execute')->once()->withArgs(fn (User $user, UserStatus $restoredStatus): bool => $user->getKey() === $removed->getKey() && $restoredStatus === $status);
    $roles = Mockery::mock(SyncUserRolesAction::class);
    $roles->shouldReceive('execute')->once()->withArgs(fn (User $user, array $roleIds): bool => $user->getKey() === $removed->getKey() && $roleIds === [ModelStub::ulid('member')]);
    $link = Mockery::mock(LinkUserIdentityAction::class);
    $link->shouldReceive('execute')->once()->withArgs(fn (User $user, IdentityProvider $provider, string $issuer, string $subject): bool => $user->getKey() === $removed->getKey() && $subject === '00u-ada')->andReturn(new UserIdentity);
    $provision = Mockery::mock(ProvisionIdentityUserAction::class);
    $provision->shouldNotReceive('execute');
    app()->instance(SsoConnectionLookup::class, $lookup);
    app()->instance(RestoreUserAction::class, $restore);
    app()->instance(SyncUserRolesAction::class, $roles);
    app()->instance(LinkUserIdentityAction::class, $link);
    app()->instance(ProvisionIdentityUserAction::class, $provision);

    $user = app(CreateScimUserAction::class)->execute($this->tenantId, new ScimUserChanges(['email' => 'ada@acme.de', 'first_name' => 'Ada', 'is_active' => $isActive, 'external_id' => '00u-ada']));

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

    expect(($this->scimError)(fn () => app(CreateScimUserAction::class)->execute($this->tenantId, new ScimUserChanges(['email' => 'ada@acme.de', 'is_active' => true]))))->toBe([409, ScimErrorType::Uniqueness]);
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

    app(UpdateScimUserAction::class)->execute($user, new ScimUserChanges(['is_active' => false, 'last_name' => 'Byron']));

    expect(collect($connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('update "users" set "last_name" = ?');
});

it('leaves the status alone while the patch does not change it', function (): void {
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $status = Mockery::mock(SetUserStatusAction::class);
    $status->shouldNotReceive('execute');
    app()->instance(SetUserStatusAction::class, $status);

    app(UpdateScimUserAction::class)->execute(($this->user)(), new ScimUserChanges(['is_active' => true]));
});

it('refuses to move a user onto an email address another account holds', function (): void {
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'exists') ? [['exists' => true]] : []);

    expect(($this->scimError)(fn () => app(UpdateScimUserAction::class)->execute(($this->user)(), new ScimUserChanges(['email' => 'grace@acme.de']))))->toBe([409, ScimErrorType::Uniqueness]);
});

it('ends the sessions and tokens of a removed user before removing it from the tenant', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $user = ($this->user)();
    $sessions = Mockery::mock(UserSessionInvalidator::class);
    $sessions->shouldReceive('invalidate')->once()->with($user);
    $delete = Mockery::mock(DeleteUserAction::class);
    $delete->shouldReceive('execute')->once()->with($user);
    app()->instance(UserSessionInvalidator::class, $sessions);
    app()->instance(DeleteUserAction::class, $delete);

    app(DeleteScimUserAction::class)->execute($user);

    expect(collect($connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('delete from "personal_access_tokens"');
});
