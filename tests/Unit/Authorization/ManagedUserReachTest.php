<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Support\Authorization\ManagedUserResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\ScopedPermissionResolver;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->ability = 'members.update';

    /** @var callable(string, list<string>):Team */
    $this->team = fn (string $seed, array $descendantIds = []): Team => ModelStub::make(Team::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $seed,
        'ancestor_team_ids' => [],
        'descendant_team_ids' => $descendantIds,
    ]);

    /** @var callable(bool, bool, ?RoleAuthority):Role */
    $this->role = function (bool $subteamVisibility, bool $carriesAbility, ?RoleAuthority $authority = null): Role {
        $role = ModelStub::make(Role::class, [
            'id' => ModelStub::ulid('reach-role-'.($subteamVisibility ? 'sub' : 'flat').($carriesAbility ? '-yes' : '-no').($authority?->value ?? '')),
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'reach-role',
            'authority' => $authority?->value,
            'grants_subteam_visibility' => $subteamVisibility,
        ]);

        $permissions = $carriesAbility
            ? [ModelStub::make(Permission::class, ['id' => ModelStub::ulid('permission-members-update'), 'name' => $this->ability])]
            : [];

        $role->setRelation('permissions', new EloquentCollection($permissions));

        return $role;
    };

    /** @var callable(list<Role>, list<Team>, array<string, list<string>>):ManagedUserResolver */
    $this->resolverFor = function (array $roles, array $scopedTeams, array $abilitiesByScope): ManagedUserResolver {
        $this->actor = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], $roles, 'reach-actor');
        $this->actor->setRelation('tenant', $this->tenant);

        $this->directory = (new FakeAuthorizationDirectory)->withScopedTeams($this->actor, $scopedTeams);

        return new ManagedUserResolver(
            ScopedPermissionResolver::install($abilitiesByScope),
            $this->directory,
        );
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reports a tenant wide reach without listing a single team', function (): void {
    $resolver = ($this->resolverFor)(
        [($this->role)(false, true)],
        [($this->team)('alpha')],
        [(string) $this->tenant->getKey() => [$this->ability]],
    );

    expect($resolver->reachOf($this->actor, $this->ability))->toBe(['tenant' => true, 'teams' => []])
        ->and($this->directory->askedFor)->not->toContain('teamsScopingAssignmentsOf');
});

it('lists only the teams the ability actually holds in', function (): void {
    $reachable = ($this->team)('alpha');
    $unreachable = ($this->team)('beta');

    $resolver = ($this->resolverFor)(
        [($this->role)(false, true)],
        [$reachable, $unreachable],
        [(string) $reachable->getKey() => [$this->ability]],
    );

    expect($resolver->reachOf($this->actor, $this->ability))->toBe([
        'tenant' => false,
        'teams' => [(string) $reachable->getKey()],
    ]);
});

it('reaches into the subteams when the role grants subteam visibility', function (): void {
    $child = ($this->team)('child');
    $parent = ($this->team)('parent', [(string) $child->getKey()]);

    $resolver = ($this->resolverFor)(
        [($this->role)(true, true)],
        [$parent],
        [(string) $parent->getKey() => [$this->ability]],
    );

    expect($resolver->reachOf($this->actor, $this->ability)['teams'])->toBe([
        (string) $parent->getKey(),
        (string) $child->getKey(),
    ]);
});

it('stops at the team itself when the role does not grant subteam visibility', function (): void {
    $child = ($this->team)('child');
    $parent = ($this->team)('parent', [(string) $child->getKey()]);

    $resolver = ($this->resolverFor)(
        [($this->role)(false, true)],
        [$parent],
        [(string) $parent->getKey() => [$this->ability]],
    );

    expect($resolver->reachOf($this->actor, $this->ability)['teams'])->toBe([(string) $parent->getKey()]);
});

it('refuses the subteams to a role that grants visibility but not the ability', function (): void {
    $child = ($this->team)('child');
    $parent = ($this->team)('parent', [(string) $child->getKey()]);

    $resolver = ($this->resolverFor)(
        [($this->role)(true, false)],
        [$parent],
        [(string) $parent->getKey() => [$this->ability]],
    );

    expect($resolver->reachOf($this->actor, $this->ability)['teams'])->toBe([(string) $parent->getKey()]);
});

it('lets a role with authority carry every ability into the subteams', function (): void {
    $child = ($this->team)('child');
    $parent = ($this->team)('parent', [(string) $child->getKey()]);

    $resolver = ($this->resolverFor)(
        [($this->role)(true, false, RoleAuthority::ScopeAdmin)],
        [$parent],
        [(string) $parent->getKey() => [$this->ability]],
    );

    expect($resolver->reachOf($this->actor, $this->ability)['teams'])->toBe([
        (string) $parent->getKey(),
        (string) $child->getKey(),
    ]);
});

it('lists a team reached twice only once', function (): void {
    $shared = ($this->team)('shared');
    $first = ($this->team)('first', [(string) $shared->getKey()]);
    $second = ($this->team)('second', [(string) $shared->getKey()]);

    $resolver = ($this->resolverFor)(
        [($this->role)(true, true)],
        [$first, $second],
        [
            (string) $first->getKey() => [$this->ability],
            (string) $second->getKey() => [$this->ability],
        ],
    );

    $teams = $resolver->reachOf($this->actor, $this->ability)['teams'];

    expect($teams)->toHaveCount(3)
        ->and(array_count_values($teams)[(string) $shared->getKey()])->toBe(1);
});

it('reaches nothing at all without a single scoped team', function (): void {
    $resolver = ($this->resolverFor)([($this->role)(true, true)], [], []);

    expect($resolver->reachOf($this->actor, $this->ability))->toBe(['tenant' => false, 'teams' => []]);
});

it('keeps the reach of one ability out of another', function (): void {
    $team = ($this->team)('alpha');

    $resolver = ($this->resolverFor)(
        [($this->role)(false, true)],
        [$team],
        [(string) $team->getKey() => ['members.view']],
    );

    expect($resolver->reachOf($this->actor, $this->ability)['teams'])->toBe([])
        ->and($resolver->reachOf($this->actor, 'members.view')['teams'])->toBe([(string) $team->getKey()]);
});
