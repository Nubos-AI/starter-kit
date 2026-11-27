<?php

declare(strict_types=1);

use App\Actions\Webhooks\BulkDeleteWebhookSubscriptionsAction;
use App\Actions\Webhooks\CreateWebhookSubscriptionAction;
use App\Actions\Webhooks\DeleteWebhookSubscriptionAction;
use App\Actions\Webhooks\RotateWebhookSecretAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Contracts\Temporal\ProcessStarterInterface;
use App\Http\Controllers\Webhooks\WebhookSubscriptionsController;
use App\Models\User;
use App\Support\Teams\TenantTeamOptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->processes = Mockery::mock(ProcessStarterInterface::class);
    $this->processes->shouldNotReceive('start');
    $this->processes->shouldNotReceive('startWithUpdate');

    $this->controller = new WebhookSubscriptionsController(
        Mockery::mock(CreateWebhookSubscriptionAction::class),
        Mockery::mock(UpdateWebhookSubscriptionAction::class),
        Mockery::mock(RotateWebhookSecretAction::class),
        Mockery::mock(DeleteWebhookSubscriptionAction::class),
        Mockery::mock(BulkDeleteWebhookSubscriptionsAction::class),
        $this->processes,
        Mockery::mock(TenantTeamOptions::class),
    );

    $this->requestBy = function (bool $isEscalated): Request {
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isEscalatedAuthority')->andReturn($isEscalated);
        $user->setRawAttributes(['id' => ModelStub::ulid('webhook-manager'), 'tenant_id' => $this->tenant->getKey()]);
        $user->setRelation('tenant', $this->tenant);

        $request = Request::create('/probe', 'POST');
        $request->setUserResolver(fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses a challenge recheck by a user without escalated authority before any challenge process starts', function (): void {
    expect(fn () => $this->controller->recheck(($this->requestBy)(false), ModelStub::ulid('subscription')))->toThrow(AuthorizationException::class);
});

it('resolves the subscription of a challenge recheck only within the tenant of the acting user', function (): void {
    $subscriptionId = ModelStub::ulid('foreign-subscription');

    $attempted = QueryShape::attemptedBy(fn () => $this->controller->recheck(($this->requestBy)(true), $subscriptionId));

    expect($attempted)->not->toBeNull()
        ->and($attempted->isScopedToTenant('webhook_subscriptions', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempted->isKeyedTo('webhook_subscriptions', $subscriptionId))->toBeTrue();
});
