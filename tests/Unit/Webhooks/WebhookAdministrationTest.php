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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticAuthorityUser;
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
    );

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

it('never exposes the stored secret of a subscription to the browser', function (): void {
    $source = file_get_contents(base_path('app/Http/Controllers/Webhooks/WebhookSubscriptionsController.php'));

    expect($source)->not->toContain('secret_previous')
        ->and($source)->not->toContain("'secret' => \$subscription->secret")
        ->and($source)->not->toContain('auth_password');
});
