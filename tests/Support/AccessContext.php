<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\PermissionResolver;
use App\Support\Authorization\RowAccess\RecordAccessRuleCompiler;
use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use App\Support\Authorization\RowAccess\TeamAccessRuleResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Tests\Support\Doubles\FakePermissionResolver;
use Tests\Support\Doubles\FakeTeamAccessRuleResolver;
use Tests\Support\Doubles\RecordingRuleCompiler;

class AccessContext
{
    public static function tenant(string $seed = 'tenant'): Tenant
    {
        $tenant = ModelStub::make(Tenant::class, ['id' => ModelStub::ulid($seed)]);

        app()->instance('current_tenant', $tenant);
        Context::addHidden('tenant_id', (string) $tenant->getKey());

        return $tenant;
    }

    public static function forgetTenant(): void
    {
        app()->forgetInstance('current_tenant');
        Context::forgetHidden('tenant_id');
    }

    public static function team(Tenant $tenant, string $seed = 'team'): Team
    {
        $team = ModelStub::make(Team::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $tenant->getKey(),
            'ancestor_team_ids' => [],
        ]);

        app()->instance('current_team', $team);
        Context::addHidden('team_id', (string) $team->getKey());

        return $team;
    }

    public static function forgetTeam(): void
    {
        app()->forgetInstance('current_team');
        Context::forgetHidden('team_id');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(Tenant $tenant, array $attributes = [], string $seed = 'user'): User
    {
        return ModelStub::make(User::class, [
            'id' => ModelStub::ulid($seed),
            'tenant_id' => $tenant->getKey(),
            ...$attributes,
        ]);
    }

    public static function actAs(User $user): User
    {
        Auth::setUser($user);

        return $user;
    }

    public static function grant(string ...$abilities): FakePermissionResolver
    {
        $resolver = new FakePermissionResolver(array_values($abilities));

        app()->instance(PermissionResolver::class, $resolver);

        return $resolver;
    }

    /**
     * @param  array<string, list<list<array<string, mixed>>>>  $byObjectType
     */
    public static function rowAccessRules(array $byObjectType): FakeTeamAccessRuleResolver
    {
        $resolver = FakeTeamAccessRuleResolver::restrictedTo($byObjectType);

        app()->instance(TeamAccessRuleResolver::class, $resolver);

        return $resolver;
    }

    public static function unrestrictedRowAccess(): FakeTeamAccessRuleResolver
    {
        $resolver = FakeTeamAccessRuleResolver::unrestricted();

        app()->instance(TeamAccessRuleResolver::class, $resolver);

        return $resolver;
    }

    public static function recordRuleCompiler(): RecordingRuleCompiler
    {
        $compiler = new RecordingRuleCompiler;

        app()->instance(RecordAccessRuleCompiler::class, $compiler);

        return $compiler;
    }

    public static function enforceRowAccess(): RowAccessEnforcement
    {
        $enforcement = app(RowAccessEnforcement::class);
        $enforcement->enable();

        return $enforcement;
    }

    public static function suspendRowAccess(): RowAccessEnforcement
    {
        $enforcement = app(RowAccessEnforcement::class);
        $enforcement->disable();

        return $enforcement;
    }
}
