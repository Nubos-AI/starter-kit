<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Enums\Timeline\TimelineChannel;
use App\Support\Audit\ActorResolver;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);
    $this->resolver = app(ActorResolver::class);

    $this->token = ModelStub::make(PersonalAccessToken::class, [
        'id' => 42,
        'name' => 'Zapier',
        'creator_id' => $this->user->getKey(),
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance('current_automation_actor');
});

it('names the api token a creator acts through and stamps the change as the api channel', function (): void {
    AccessContext::actAs($this->user->withAccessToken($this->token));

    expect($this->resolver->apiTokenId(ActorType::User->value))->toBe(42)
        ->and($this->resolver->channel(ActorType::User->value))->toBe(TimelineChannel::Api);
});

it('carries no api context for a session request even on an api path', function (): void {
    app()->instance('request', Request::create('/api/v1/records/x', 'POST'));
    AccessContext::actAs($this->user->withAccessToken(new TransientToken));

    expect($this->resolver->apiTokenId(ActorType::User->value))->toBeNull()
        ->and($this->resolver->channel(ActorType::User->value))->toBe(TimelineChannel::Web);
});

it('carries no api context for a signed in user without any token', function (): void {
    AccessContext::actAs($this->user);

    expect($this->resolver->apiTokenId(ActorType::User->value))->toBeNull()
        ->and($this->resolver->channel(ActorType::User->value))->toBe(TimelineChannel::Web);
});

it('carries no api context when nobody is signed in', function (): void {
    expect($this->resolver->apiTokenId(null))->toBeNull()
        ->and($this->resolver->channel(null))->toBe(TimelineChannel::System);
});

it('leaves the token out when the change is attributed to an automation or the system', function (string $actorType, TimelineChannel $channel): void {
    AccessContext::actAs($this->user->withAccessToken($this->token));

    expect($this->resolver->apiTokenId($actorType))->toBeNull()
        ->and($this->resolver->channel($actorType))->toBe($channel);
})->with([
    'automation' => [ActorType::Automation->value, TimelineChannel::Automation],
    'system' => [ActorType::System->value, TimelineChannel::System],
]);
