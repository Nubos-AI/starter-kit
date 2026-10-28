<?php

declare(strict_types=1);

use App\Actions\Authorization\AssignRoleAction;
use App\Actions\Authorization\SeedGlobalPermissionsAction;
use App\Actions\Authorization\SeedTenantRolesAction;
use App\Actions\Teams\CreateTeamAction;
use App\Actions\Tenancy\ProvisionTenantAction;
use App\Contracts\Modules\TenantProvisioningExtensionInterface;
use App\DTOs\Tenancy\TenantRegistrationData;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Users\Salutation;
use App\Enums\Users\UserStatus;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Modules\TenantProvisioningExtensions;
use App\Support\Tenancy\TenantBinder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());

    $this->steps = [];
    $this->written = [];

    $intercept = function (string $class, string $seed): void {
        $class::creating(function (Model $model) use ($class, $seed): bool {
            $model->setAttribute('id', (string) ModelStub::ulid($seed));
            $model->exists = true;
            $this->steps[] = "create {$class}";
            $this->written[$class] = $model->getAttributes();

            return false;
        });

        $class::updating(function (Model $model) use ($class): bool {
            $this->steps[] = "update {$class}";
            $this->written[$class] = $model->getAttributes();

            return false;
        });
    };

    $intercept(Tenant::class, 'registered-tenant');
    $intercept(User::class, 'registered-owner');

    $this->ownerRole = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('owner-role'),
        'authority' => RoleAuthority::ScopeAdmin->value,
        'is_system' => true,
    ]);

    $this->members = Mockery::mock(BelongsToMany::class);
    $this->team = Mockery::mock(Team::class)->makePartial();
    $this->team->setAttribute('id', (string) ModelStub::ulid('registered-team'));
    $this->team->shouldReceive('users')->andReturn($this->members);

    $this->binder = Mockery::mock(TenantBinder::class);
    $this->binder->shouldReceive('runWith')->andReturnUsing(function (Tenant $tenant, callable $work): mixed {
        $this->steps[] = 'bind tenant';
        $this->boundTenant = $tenant;

        return $work($tenant);
    });

    $this->permissions = Mockery::mock(SeedGlobalPermissionsAction::class);
    $this->permissions->shouldReceive('execute')->andReturnUsing(function (): void {
        $this->steps[] = 'seed permissions';
    });

    $this->roles = Mockery::mock(SeedTenantRolesAction::class);
    $this->roles->shouldReceive('execute')->andReturnUsing(function (): Role {
        $this->steps[] = 'seed roles';

        return $this->ownerRole;
    });

    $this->teams = Mockery::mock(CreateTeamAction::class);
    $this->teams->shouldReceive('execute')->andReturnUsing(function (User $actor, array $input): Team {
        $this->steps[] = 'create team';
        $this->teamActor = $actor;
        $this->teamInput = $input;

        return $this->team;
    });

    $this->members->shouldReceive('syncWithoutDetaching')->andReturnUsing(function (array $ids): array {
        $this->steps[] = 'join team';
        $this->joined = $ids;

        return ['attached' => $ids, 'detached' => [], 'updated' => []];
    });

    $this->assignRole = Mockery::mock(AssignRoleAction::class);

    $this->provisionedTenants = [];

    $extension = new class($this) implements TenantProvisioningExtensionInterface
    {
        public function __construct(private readonly object $test) {}

        public function provisioned(Tenant $tenant): void
        {
            $this->test->steps[] = 'provision extensions';
            $this->test->provisionedTenants[] = $tenant->getKey();
        }
    };

    $this->action = fn (): ProvisionTenantAction => new ProvisionTenantAction(
        $this->binder,
        $this->permissions,
        $this->roles,
        $this->teams,
        $this->assignRole,
        new TenantProvisioningExtensions([$extension]),
    );

    $this->data = new TenantRegistrationData(
        companyName: 'Nordlicht Handels GmbH',
        salutation: Salutation::Mix,
        firstName: 'Ada',
        lastName: 'Lovelace',
        email: 'ada@nordlicht.test',
        password: 'Str0ng-Passphrase!42',
    );
});

afterEach(function (): void {
    Model::clearBootedModels();
});

it('builds the tenant before anything that belongs to it and finishes with the owner role', function (): void {
    $this->assignRole->shouldReceive('assign')->once()->andReturnUsing(function (): RoleAssignment {
        $this->steps[] = 'assign owner role';

        return new RoleAssignment;
    });

    ($this->action)()->execute($this->data);

    expect($this->steps)->toBe([
        'create '.Tenant::class,
        'bind tenant',
        'seed permissions',
        'seed roles',
        'create '.User::class,
        'create team',
        'join team',
        'update '.User::class,
        'assign owner role',
        'provision extensions',
    ]);
});

it('names the tenant after the company and gives it a unique lowercase slug', function (): void {
    $this->assignRole->shouldReceive('assign')->once()->andReturn(new RoleAssignment);

    ($this->action)()->execute($this->data);

    $tenant = $this->written[Tenant::class];

    expect($tenant['name'])->toBe('Nordlicht Handels GmbH')
        ->and($tenant['slug'])->toBe(strtolower($tenant['slug']))
        ->and(Str::isUlid($tenant['slug']))->toBeTrue()
        ->and($this->boundTenant->name)->toBe('Nordlicht Handels GmbH');
});

it('creates the registering user as an accepted member of the new tenant', function (): void {
    $this->assignRole->shouldReceive('assign')->once()->andReturn(new RoleAssignment);

    $owner = ($this->action)()->execute($this->data);

    expect($owner->tenant_id)->toBe((string) ModelStub::ulid('registered-tenant'))
        ->and($owner->status)->toBe(UserStatus::Accepted)
        ->and($owner->salutation)->toBe(Salutation::Mix)
        ->and($owner->first_name)->toBe('Ada')
        ->and($owner->last_name)->toBe('Lovelace')
        ->and($owner->email)->toBe('ada@nordlicht.test');
});

it('opens a root team named after the company that the owner leads, joins and works in', function (): void {
    $this->assignRole->shouldReceive('assign')->once()->andReturn(new RoleAssignment);

    $owner = ($this->action)()->execute($this->data);

    expect($this->teamActor)->toBe($owner)
        ->and($this->teamInput)->toBe([
            'name' => 'Nordlicht Handels GmbH',
            'owner_id' => (string) $owner->getKey(),
        ])
        ->and($this->joined)->toBe([(string) $owner->getKey()])
        ->and($this->written[User::class]['current_team_id'])->toBe((string) $this->team->getKey());
});

it('grants the owner the escalated role of the seeded set scoped to the new tenant', function (): void {
    $this->assignRole->shouldReceive('assign')
        ->once()
        ->withArgs(fn (User $user, Role $role, Tenant $scope): bool => $role === $this->ownerRole
            && $scope->getKey() === (string) ModelStub::ulid('registered-tenant')
            && $user->email === 'ada@nordlicht.test')
        ->andReturn(new RoleAssignment);

    ($this->action)()->execute($this->data);
});

it('hands the furnished tenant to every module that provisions tenant content', function (): void {
    $this->assignRole->shouldReceive('assign')->once()->andReturn(new RoleAssignment);

    ($this->action)()->execute($this->data);

    expect($this->provisionedTenants)->toBe([(string) ModelStub::ulid('registered-tenant')]);
});
