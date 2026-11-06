<?php

declare(strict_types=1);

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Scim\CreateScimGroupAction;
use App\Actions\Scim\DeleteScimGroupAction;
use App\Actions\Scim\SyncScimGroupMembersAction;
use App\Actions\Scim\UpdateScimGroupAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Teams\DeleteTeamAction;
use App\Actions\Teams\UpdateTeamAction;
use App\DTOs\Scim\ScimFilter;
use App\DTOs\Scim\ScimGroupChanges;
use App\Enums\Scim\ScimErrorType;
use App\Exceptions\Scim\ScimException;
use App\Http\Controllers\Scim\ScimGroupsController;
use App\Http\Resources\Scim\ScimGroupResource;
use App\Models\ScimToken;
use App\Models\Team;
use App\Models\User;
use App\Support\Scim\ScimGroupDirectory;
use App\Support\Scim\ScimGroupMapper;
use App\Support\Scim\ScimPatchParser;
use App\Support\Tenancy\TenantUserIdResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');

    $this->tenant = AccessContext::tenant('acme');
    $this->tenantId = (string) $this->tenant->getKey();
    $this->token = ModelStub::make(ScimToken::class, ['tenant_id' => $this->tenantId]);
    $this->ada = ModelStub::ulid('ada');
    $this->bob = ModelStub::ulid('bob');

    $this->team = fn (array $members = []): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenantId,
        'name' => 'Vertrieb',
        'created_at' => '2026-10-01 08:00:00',
        'updated_at' => '2026-10-02 08:00:00',
    ], ['users' => ModelStub::collection(User::class, $members)]);

    $this->request = function (string $method = 'GET', array $query = [], ?array $body = null): Request {
        $request = Request::create('/scim/v2/Groups', $method, $query, [], [], ['CONTENT_TYPE' => 'application/scim+json'], $body === null ? null : json_encode($body));
        $request->attributes->set('scim_token', $this->token);

        return $request;
    };

    $this->patch = static fn (array $operations): array => ['schemas' => ['urn:ietf:params:scim:api:messages:2.0:PatchOp'], 'Operations' => $operations];

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
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('presents a team as a scim group with its members', function (): void {
    $resource = (new ScimGroupResource(($this->team)([['id' => $this->ada, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@acme.de']])))->resolve();

    expect($resource)->toMatchArray([
        'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Group'],
        'id' => ModelStub::ulid('sales'),
        'displayName' => 'Vertrieb',
        'members' => [['value' => $this->ada, 'display' => 'Ada Lovelace', '$ref' => url("/scim/v2/Users/{$this->ada}")]],
    ])
        ->and($resource['meta']['resourceType'])->toBe('Group')
        ->and($resource['meta']['location'])->toBe(url('/scim/v2/Groups/'.ModelStub::ulid('sales')))
        ->and($resource['meta']['version'])->toStartWith('W/"');
});

it('turns group patches into member and name changes', function (array $operations, ?string $displayName, ?array $replace, array $add, array $remove): void {
    $changes = app(ScimGroupMapper::class)->fromPatch(app(ScimPatchParser::class)->parse(($this->patch)($operations)));

    expect($changes->displayName)->toBe($displayName)
        ->and($changes->replaceMembers)->toBe($replace)
        ->and($changes->addMembers)->toBe($add)
        ->and($changes->removeMembers)->toBe($remove);
})->with([
    'add members' => [[['op' => 'Add', 'path' => 'members', 'value' => [['value' => 'u1'], ['value' => 'u2']]]], null, null, ['u1', 'u2'], []],
    'remove one member by filter' => [[['op' => 'Remove', 'path' => 'members[value eq "u1"]']], null, null, [], ['u1']],
    'remove listed members' => [[['op' => 'remove', 'path' => 'members', 'value' => [['value' => 'u2']]]], null, null, [], ['u2']],
    'remove all members' => [[['op' => 'remove', 'path' => 'members']], null, [], [], []],
    'replace members' => [[['op' => 'replace', 'path' => 'members', 'value' => [['value' => 'u3']]]], null, ['u3'], [], []],
    'rename by path' => [[['op' => 'replace', 'path' => 'displayName', 'value' => 'Vertrieb Nord']], 'Vertrieb Nord', null, [], []],
    'rename without path' => [[['op' => 'replace', 'value' => ['id' => 'x', 'displayName' => 'Vertrieb Süd']]], 'Vertrieb Süd', null, [], []],
]);

it('reads a full group resource for create and replace and refuses one without a name', function (): void {
    $changes = app(ScimGroupMapper::class)->fromResource(['displayName' => ' Vertrieb ', 'members' => [['value' => 'u1']]]);

    expect($changes->displayName)->toBe('Vertrieb')
        ->and($changes->replaceMembers)->toBe(['u1'])
        ->and(($this->scimError)(fn () => app(ScimGroupMapper::class)->fromResource(['members' => []])))->toBe([400, ScimErrorType::InvalidValue]);
});

it('reads the teams of the token tenant and filters them by display name', function (): void {
    $query = QueryShape::of(app(ScimGroupDirectory::class)->query($this->tenantId, new ScimFilter('displayname', 'Vertrieb')));

    expect($query->isScopedToTenant('teams', $this->tenantId))->toBeTrue()
        ->and($query->hasBinding('Vertrieb'))->toBeTrue()
        ->and($query->hidesSoftDeleted('teams'))->toBeTrue();
});

it('lists the groups of the tenant and answers 404 for a team of another tenant', function (): void {
    $directory = Mockery::mock(ScimGroupDirectory::class);
    $directory->shouldReceive('page')->once()->with($this->tenantId, null, 0, 100)->andReturn([1, new Collection([($this->team)()])]);
    $directory->shouldReceive('find')->once()->with($this->tenantId, 'foreign-team')->andReturnNull();
    app()->instance(ScimGroupDirectory::class, $directory);

    $list = app(ScimGroupsController::class)->index(($this->request)())->getData(true);

    expect($list['totalResults'])->toBe(1)
        ->and($list['Resources'][0]['displayName'])->toBe('Vertrieb')
        ->and(($this->scimError)(fn () => app(ScimGroupsController::class)->show(($this->request)(), 'foreign-team')))->toBe([404, null]);
});

it('hands a patch of a group of the tenant to the update action', function (): void {
    $team = ($this->team)();
    $directory = Mockery::mock(ScimGroupDirectory::class);
    $directory->shouldReceive('find')->with($this->tenantId, ModelStub::ulid('sales'))->andReturn($team);
    app()->instance(ScimGroupDirectory::class, $directory);
    $update = Mockery::mock(UpdateScimGroupAction::class);
    $update->shouldReceive('execute')->once()->withArgs(fn ($tenant, Team $target, ScimGroupChanges $changes): bool => $tenant->is($this->tenant) && $target === $team && $changes->addMembers === [$this->ada])->andReturn($team);
    app()->instance(UpdateScimGroupAction::class, $update);

    $response = app(ScimGroupsController::class)->update(($this->request)('PATCH', [], ($this->patch)([['op' => 'add', 'path' => 'members', 'value' => [['value' => $this->ada]]]])), ModelStub::ulid('sales'));

    expect($response->getStatusCode())->toBe(200);
});

it('adds and removes only human members of the tenant and refuses unknown ones', function (): void {
    $connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $resolver = Mockery::mock(TenantUserIdResolver::class);
    $resolver->shouldReceive('resolve')->with($this->tenantId, [$this->ada])->andReturn([$this->ada]);
    $resolver->shouldReceive('resolve')->with($this->tenantId, [$this->bob])->andReturn([]);
    app()->instance(TenantUserIdResolver::class, $resolver);

    app(SyncScimGroupMembersAction::class)->execute(($this->team)(), new ScimGroupChanges(null, null, [$this->ada], []));

    expect(collect($connection->writtenStatements)->pluck('sql')->implode(' '))->toContain('insert into "team_user"')
        ->and(($this->scimError)(fn () => app(SyncScimGroupMembersAction::class)->execute(($this->team)(), new ScimGroupChanges(null, null, [$this->bob], []))))->toBe([400, ScimErrorType::InvalidValue]);
});

it('creates, renames and removes teams through the team actions in the name of the tenant service user', function (): void {
    StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
    $service = ModelStub::make(User::class, ['tenant_id' => $this->tenantId, 'is_service' => true]);
    $team = ($this->team)();
    $provision = Mockery::mock(ProvisionServiceUserAction::class);
    $provision->shouldReceive('execute')->with($this->tenant)->andReturn($service);
    $create = Mockery::mock(CreateTeamAction::class);
    $create->shouldReceive('execute')->once()->with($service, ['name' => 'Vertrieb'])->andReturn($team);
    $rename = Mockery::mock(UpdateTeamAction::class);
    $rename->shouldReceive('execute')->once()->with($service, $team, ['name' => 'Vertrieb Nord'])->andReturn($team);
    $delete = Mockery::mock(DeleteTeamAction::class);
    $delete->shouldReceive('execute')->once()->with($service, $team);
    $members = Mockery::mock(SyncScimGroupMembersAction::class);
    $members->shouldReceive('execute')->once();
    app()->instance(ProvisionServiceUserAction::class, $provision);
    app()->instance(CreateTeamAction::class, $create);
    app()->instance(UpdateTeamAction::class, $rename);
    app()->instance(DeleteTeamAction::class, $delete);
    app()->instance(SyncScimGroupMembersAction::class, $members);

    expect(app(CreateScimGroupAction::class)->execute($this->tenant, new ScimGroupChanges('Vertrieb', [$this->ada], [], [])))->toBe($team);

    app(UpdateScimGroupAction::class)->execute($this->tenant, $team, new ScimGroupChanges('Vertrieb Nord', null, [], []));
    app(DeleteScimGroupAction::class)->execute($this->tenant, $team);
});
