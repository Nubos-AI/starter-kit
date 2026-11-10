<?php

declare(strict_types=1);

use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Actions\Webhooks\BulkDeleteWebhookSubscriptionsAction;
use App\Actions\Webhooks\CreateWebhookSubscriptionAction;
use App\Actions\Webhooks\DeleteWebhookSubscriptionAction;
use App\Actions\Webhooks\RotateWebhookSecretAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Http\Controllers\Webhooks\WebhookSubscriptionsController;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Teams\TenantTeamOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->subscriptionId = ModelStub::ulid('subscription');

    $this->controller = new WebhookSubscriptionsController(
        Mockery::mock(CreateWebhookSubscriptionAction::class),
        Mockery::mock(UpdateWebhookSubscriptionAction::class),
        Mockery::mock(RotateWebhookSecretAction::class),
        Mockery::mock(DeleteWebhookSubscriptionAction::class),
        Mockery::mock(BulkDeleteWebhookSubscriptionsAction::class),
        Mockery::mock(ActivateSubscriptionViaChallengeAction::class),
        $this->teamOptions = Mockery::mock(TenantTeamOptions::class),
    );
    $this->teamOptions->shouldReceive('forUser')->byDefault()->andReturn([]);

    /** @var callable(bool, ?Tenant):User */
    $this->actor = function (bool $escalated, ?Tenant $tenant = null): User {
        $tenant ??= $this->tenant;

        $user = ModelStub::make(StaticAuthorityUser::class, [
            'id' => ModelStub::ulid('webhook-admin'),
            'tenant_id' => $tenant->getKey(),
        ], ['tenant' => $tenant]);

        $user->escalated = $escalated;

        return $user;
    };

    /** @var callable(?User):Request */
    $this->requestOf = static function (?User $user): Request {
        $request = Request::create('/engine/webhooks', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);
        $request->setLaravelSession(new Store('testing', new ArraySessionHandler(60)));

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses every webhook management verb to an actor without escalated authority', function (string $method, array $arguments): void {
    $request = ($this->requestOf)(($this->actor)(escalated: false));

    $reachedTheDatabase = QueryShape::attemptedBy(function () use ($method, $arguments, $request): void {
        try {
            $this->controller->{$method}($request, ...$arguments);
        } catch (AuthorizationException) {
            return;
        }
    });

    expect(fn (): mixed => $this->controller->{$method}($request, ...$arguments))
        ->toThrow(AuthorizationException::class)
        ->and($reachedTheDatabase)->toBeNull();
})->with([
    'index' => ['index', []],
    'create' => ['create', []],
    'store' => ['store', []],
    'edit' => ['edit', ['01JABCDEF0123456789ABCDEFG']],
    'update' => ['update', ['01JABCDEF0123456789ABCDEFG']],
    'rotate' => ['rotateSecret', ['01JABCDEF0123456789ABCDEFG']],
    'recheck' => ['recheck', ['01JABCDEF0123456789ABCDEFG']],
    'destroy' => ['destroy', ['01JABCDEF0123456789ABCDEFG']],
    'bulk destroy' => ['bulkDestroy', []],
]);

it('refuses every webhook management verb to a guest', function (): void {
    $request = ($this->requestOf)(null);

    expect(fn (): mixed => $this->controller->index($request))->toThrow(AuthorizationException::class);
});

it('refuses webhook management without a tenant context', function (): void {
    $user = ModelStub::make(StaticAuthorityUser::class, [
        'id' => ModelStub::ulid('webhook-admin'),
        'tenant_id' => $this->tenant->getKey(),
    ], ['tenant' => null]);

    $user->escalated = true;

    expect(fn (): mixed => $this->controller->index(($this->requestOf)($user)))
        ->toThrow(AuthorizationException::class);
});

it('lists only the subscriptions of the own tenant', function (): void {
    $request = ($this->requestOf)(($this->actor)(escalated: true));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->index($request));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('webhook_subscriptions'))->toBeTrue()
        ->and($shape->bindings)->toContain((string) $this->tenant->getKey());
});

it('resolves a single subscription inside the own tenant and never by id alone', function (string $method): void {
    $request = ($this->requestOf)(($this->actor)(escalated: true));

    $shape = QueryShape::attemptedBy(fn (): mixed => $this->controller->{$method}($request, $this->subscriptionId));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('webhook_subscriptions'))->toBeTrue()
        ->and($shape->bindings)->toContain((string) $this->tenant->getKey())
        ->and($shape->bindings)->toContain($this->subscriptionId);
})->with(['edit', 'update', 'rotateSecret', 'recheck', 'destroy']);

it('binds every webhook management route to the authenticated application middleware group', function (string $name): void {
    $route = RouteShape::named($name);

    expect($route->handledBy())->toContain('WebhookSubscriptionsController')
        ->and($route->resolvedMiddleware())->toContain('Illuminate\Auth\Middleware\Authenticate');
})->with([
    'engine.webhooks.index',
    'engine.webhooks.create',
    'engine.webhooks.store',
    'engine.webhooks.bulkDestroy',
    'engine.webhooks.edit',
    'engine.webhooks.update',
    'engine.webhooks.rotate',
    'engine.webhooks.recheck',
    'engine.webhooks.destroy',
]);

it('never exposes a stored secret or password of a subscription to the browser', function (string $page): void {
    $encrypted = [
        'secret' => Crypt::encryptString('whsec_stored_current'),
        'secret_previous' => Crypt::encryptString('whsec_stored_previous'),
        'auth_password' => Crypt::encryptString('stored-basic-password'),
    ];

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "webhook_subscriptions"') ? [[
        'id' => $this->subscriptionId,
        'tenant_id' => (string) $this->tenant->getKey(),
        'name' => 'Buchhaltung',
        'target_url' => 'https://hooks.example.test/in',
        'auth_username' => 'hook-user',
        'status' => 'active',
        'event_types' => '[]',
        'consecutive_failures' => 0,
        ...$encrypted,
    ]] : []);

    $request = ($this->requestOf)(($this->actor)(escalated: true));
    $request->headers->set('X-Inertia', 'true');

    try {
        $response = $page === 'index'
            ? $this->controller->index($request)
            : $this->controller->edit($request, $this->subscriptionId);

        $payload = (string) $response->toResponse($request)->getContent();
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($payload)->toContain('Buchhaltung');

    foreach (['whsec_stored_current', 'whsec_stored_previous', 'stored-basic-password', ...array_values($encrypted)] as $leak) {
        expect($payload)->not->toContain(addcslashes($leak, '/'))
            ->and($payload)->not->toContain($leak);
    }
})->with(['index', 'edit']);

it('hands the edit form the team of the subscription and the living teams of the tenant as options', function (): void {
    $actor = ($this->actor)(escalated: true);
    $this->teamOptions->shouldReceive('forUser')->once()->with($actor)->andReturn([
        ['value' => ModelStub::ulid('sales-team'), 'label' => 'Vertrieb Nord'],
    ]);

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "webhook_subscriptions"') ? [[
        'id' => $this->subscriptionId,
        'tenant_id' => (string) $this->tenant->getKey(),
        'team_id' => ModelStub::ulid('sales-team'),
        'name' => 'Buchhaltung',
        'target_url' => 'https://hooks.example.test/in',
        'status' => 'active',
        'event_types' => '[]',
        'consecutive_failures' => 0,
    ]] : []);

    $request = ($this->requestOf)($actor);
    $request->headers->set('X-Inertia', 'true');

    try {
        $props = json_decode((string) $this->controller->edit($request, $this->subscriptionId)->toResponse($request)->getContent(), true)['props'];
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect($props['subscription']['teamId'])->toBe(ModelStub::ulid('sales-team'))
        ->and($props['teamOptions'])->toBe([['value' => ModelStub::ulid('sales-team'), 'label' => 'Vertrieb Nord']]);
});

it('lists the team of a team scoped subscription and leaves a system wide one without a team', function (): void {
    $tenantId = (string) $this->tenant->getKey();
    $row = fn (string $seed, ?string $teamId): array => [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $tenantId,
        'team_id' => $teamId,
        'name' => $seed,
        'target_url' => 'https://hooks.example.test/in',
        'status' => 'active',
        'event_types' => '[]',
        'consecutive_failures' => 0,
    ];

    StaticQueryConnection::install(fn (string $sql): array => match (true) {
        str_contains($sql, 'from "webhook_subscriptions"') => [$row('scoped', ModelStub::ulid('sales-team')), $row('system', null)],
        str_contains($sql, 'from "teams"') => [['id' => ModelStub::ulid('sales-team'), 'tenant_id' => $tenantId, 'name' => 'Vertrieb Nord']],
        default => [],
    });

    $request = ($this->requestOf)(($this->actor)(escalated: true));
    $request->headers->set('X-Inertia', 'true');

    try {
        $rows = json_decode((string) $this->controller->index($request)->toResponse($request)->getContent(), true)['props']['subscriptions'];
    } finally {
        StaticQueryConnection::uninstall();
    }

    expect(array_column($rows, 'team', 'name'))->toBe(['scoped' => 'Vertrieb Nord', 'system' => null]);
});
