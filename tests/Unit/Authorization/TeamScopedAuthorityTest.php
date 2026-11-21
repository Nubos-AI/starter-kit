<?php

declare(strict_types=1);

use App\Actions\Api\BulkRevokeApiTokensAction;
use App\Actions\Api\CreateApiTokenAction;
use App\Actions\Api\RevokeApiTokenAction;
use App\Actions\Api\RotateApiTokenAction;
use App\Actions\ConfigBundle\PrepareConfigImportAction;
use App\Actions\Notifications\UpdateNotificationPreferencesAction;
use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Actions\Webhooks\BulkDeleteWebhookSubscriptionsAction;
use App\Actions\Webhooks\CreateWebhookSubscriptionAction;
use App\Actions\Webhooks\DeleteWebhookSubscriptionAction;
use App\Actions\Webhooks\RotateWebhookSecretAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Enums\Authorization\RoleAuthority;
use App\Enums\Users\UserStatus;
use App\Http\Controllers\Api\ApiTokensController;
use App\Http\Controllers\Notifications\NotificationPreferencesController;
use App\Http\Controllers\Webhooks\WebhookSubscriptionsController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Api\ApiTokenPresenter;
use App\Support\Api\ApiTokenRevocationGuard;
use App\Support\Authorization\AuthorizationDirectory;
use App\Support\Authorization\ManagedUserResolver;
use App\Support\Authorization\PermissionResolver;
use App\Support\Teams\TenantTeamOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\TeamRoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('scoped-authority-tenant');

    $this->team = TeamRoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'scoped-authority-team');
    $this->team->setRelation('tenant', $this->tenant);
    app()->instance('current_team', $this->team);

    $this->adminRole = fn (RoleAuthority $authority): Role => ModelStub::make(Role::class, [
        'id' => ModelStub::ulid("role-{$authority->value}"),
        'tenant_id' => $this->tenant->getKey(),
        'name' => $authority->value,
        'authority' => $authority->value,
        'grants_subteam_visibility' => false,
    ], ['permissions' => ModelStub::collection(Permission::class, [])]);

    $this->adminIn = function (string $level, RoleAuthority $authority = RoleAuthority::ScopeAdmin): User {
        $holder = RoleHolder::make([
            'tenant_id' => $this->tenant->getKey(),
            'status' => UserStatus::Accepted->value,
        ], [], "{$level}-admin");

        $holder->setRelation('tenant', $this->tenant);
        $holder->withRolesIn($level === 'team' ? $this->team : $this->tenant, [($this->adminRole)($authority)]);

        return AccessContext::actAs($holder);
    };

    $this->requestOf = static function (User $user): Request {
        $request = Request::create('/engine', 'GET');
        $request->setUserResolver(static fn (): User => $user);
        $request->setLaravelSession(new Store('testing', new ArraySessionHandler(60)));

        return $request;
    };

    $this->webhooks = function (): WebhookSubscriptionsController {
        $teamOptions = Mockery::mock(TenantTeamOptions::class);
        $teamOptions->shouldReceive('forUser')->andReturn([]);

        return new WebhookSubscriptionsController(
            Mockery::mock(CreateWebhookSubscriptionAction::class),
            Mockery::mock(UpdateWebhookSubscriptionAction::class),
            Mockery::mock(RotateWebhookSecretAction::class),
            Mockery::mock(DeleteWebhookSubscriptionAction::class),
            Mockery::mock(BulkDeleteWebhookSubscriptionsAction::class),
            Mockery::mock(ActivateSubscriptionViaChallengeAction::class),
            $teamOptions,
        );
    };

    $this->apiTokens = fn (): ApiTokensController => new ApiTokensController(
        Mockery::mock(CreateApiTokenAction::class),
        Mockery::mock(RotateApiTokenAction::class),
        Mockery::mock(RevokeApiTokenAction::class),
        Mockery::mock(BulkRevokeApiTokensAction::class),
        new ApiTokenPresenter,
        Mockery::mock(ApiTokenRevocationGuard::class),
    );
});

afterEach(function (): void {
    Auth::forgetUser();
    app()->forgetInstance('current_team');
    AccessContext::forgetTenant();
});

it('does not count an authority held only for the active team as escalated', function (RoleAuthority $authority): void {
    $teamAdmin = ($this->adminIn)('team', $authority);

    expect($teamAdmin->isEscalatedAuthority())->toBeFalse()
        ->and($teamAdmin->hasRoleWithAuthority($authority))->toBeFalse();
})->with([RoleAuthority::SuperAdmin, RoleAuthority::ScopeAdmin]);

it('counts an authority held at tenant level as escalated', function (RoleAuthority $authority): void {
    $tenantAdmin = ($this->adminIn)('tenant', $authority);

    expect($tenantAdmin->isEscalatedAuthority())->toBeTrue()
        ->and($tenantAdmin->hasRoleWithAuthority($authority))->toBeTrue();
})->with([RoleAuthority::SuperAdmin, RoleAuthority::ScopeAdmin]);

it('never lets the gate grant a team scoped admin every ability', function (): void {
    $teamAdmin = ($this->adminIn)('team');

    expect(Gate::forUser($teamAdmin)->allows('api-tokens.manage'))->toBeFalse()
        ->and(Gate::forUser($teamAdmin)->allows('config.export'))->toBeFalse()
        ->and($teamAdmin->hasPermission('config.import'))->toBeFalse();
});

it('lets the gate grant a tenant level admin every ability', function (): void {
    $tenantAdmin = ($this->adminIn)('tenant');

    expect(Gate::forUser($tenantAdmin)->allows('api-tokens.manage'))->toBeTrue()
        ->and(Gate::forUser($tenantAdmin)->allows('config.export'))->toBeTrue()
        ->and($tenantAdmin->hasPermission('config.import'))->toBeTrue();
});

it('keeps the reach of a team scoped admin over the own team', function (): void {
    $teamAdmin = ($this->adminIn)('team');
    app()->instance(AuthorizationDirectory::class, (new FakeAuthorizationDirectory)->withScopedTeams($teamAdmin, [$this->team]));

    expect(app(PermissionResolver::class)->verdictFor($teamAdmin, 'teams.update', $this->team))->toBeTrue()
        ->and(app(ManagedUserResolver::class)->reachOf($teamAdmin, 'teams.update'))
        ->toBe(['tenant' => false, 'teams' => [(string) $this->team->getKey()]]);
});

it('refuses a team scoped admin the webhook administration of the tenant', function (): void {
    $request = ($this->requestOf)(($this->adminIn)('team'));

    expect(fn (): mixed => ($this->webhooks)()->index($request))->toThrow(AuthorizationException::class);
});

it('admits a tenant level admin to the webhook administration of the tenant', function (): void {
    $request = ($this->requestOf)(($this->adminIn)('tenant'));

    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->webhooks)()->index($request));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('webhook_subscriptions'))->toBeTrue();
});

it('refuses a team scoped admin the api token administration of the tenant', function (): void {
    $request = ($this->requestOf)(($this->adminIn)('team'));

    expect(fn (): mixed => ($this->apiTokens)()->index($request))->toThrow(AuthorizationException::class);
});

it('admits a tenant level admin to the api token administration of the tenant', function (): void {
    $request = ($this->requestOf)(($this->adminIn)('tenant'));

    $shape = QueryShape::attemptedBy(fn (): mixed => ($this->apiTokens)()->index($request));

    expect($shape)->not->toBeNull();
});

it('refuses a team scoped admin the tenant wide notification defaults', function (): void {
    $request = ($this->requestOf)(($this->adminIn)('team'));
    $action = Mockery::mock(UpdateNotificationPreferencesAction::class);
    $action->shouldNotReceive('writeTenantDefaults');

    expect(fn (): mixed => (new NotificationPreferencesController($action))->updateTenantDefaults($request))
        ->toThrow(AuthorizationException::class);
});

it('lets a tenant level admin write the tenant wide notification defaults', function (): void {
    $request = ($this->requestOf)(($this->adminIn)('tenant'));
    $action = Mockery::mock(UpdateNotificationPreferencesAction::class);
    $action->shouldReceive('writeTenantDefaults')->once()->andReturn([]);

    (new NotificationPreferencesController($action))->updateTenantDefaults($request);
});

it('refuses a team scoped admin a config import into the tenant', function (): void {
    expect(app(PrepareConfigImportAction::class)->refusalFor(($this->adminIn)('team')))->not->toBeNull();
});

it('lets a tenant level admin prepare a config import into the tenant', function (): void {
    expect(app(PrepareConfigImportAction::class)->refusalFor(($this->adminIn)('tenant')))->toBeNull();
});
