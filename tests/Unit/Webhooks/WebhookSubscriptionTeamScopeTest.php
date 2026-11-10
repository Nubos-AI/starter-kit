<?php

declare(strict_types=1);

use App\Actions\Api\ProvisionServiceUserAction;
use App\Actions\Webhooks\ActivateSubscriptionViaChallengeAction;
use App\Actions\Webhooks\CreateWebhookSubscriptionAction;
use App\Actions\Webhooks\UpdateWebhookSubscriptionAction;
use App\Enums\Webhooks\WebhookSubscriptionStatus;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticPresenceVerifier;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->presence = fn (array $teamIds): StaticPresenceVerifier => StaticPresenceVerifier::install([
        'teams' => $teamIds,
        'roles' => ['Integration'],
    ])->ownedByTenant($this->tenantId);
    $this->verifier = ($this->presence)([ModelStub::ulid('sales-team'), ModelStub::ulid('service-team')]);

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, 'from "roles"') => [['id' => ModelStub::ulid('integration'), 'tenant_id' => $this->tenantId, 'name' => 'Integration']],
            default => [],
        },
        static fn (): int => 1,
    );

    $this->activate = Mockery::mock(ActivateSubscriptionViaChallengeAction::class);
    $this->activate->shouldReceive('execute')->andReturn(WebhookSubscriptionStatus::Pending);

    $serviceUsers = Mockery::mock(ProvisionServiceUserAction::class);
    $serviceUsers->shouldReceive('execute')->andReturn(ModelStub::make(User::class, ['id' => ModelStub::ulid('service-user'), 'tenant_id' => $this->tenantId]));

    $this->create = new CreateWebhookSubscriptionAction($serviceUsers, $this->activate);
    $this->update = new UpdateWebhookSubscriptionAction($this->activate);

    $this->input = static fn (array $overrides = []): array => [
        'name' => 'Vertrieb Nord',
        'target_url' => 'https://hooks.example.test/in',
        'roleName' => 'Integration',
        'event_types' => ['record.created'],
        ...$overrides,
    ];

    $this->subscription = fn (array $attributes = []): WebhookSubscription => ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid('subscription'),
        'tenant_id' => $this->tenantId,
        'name' => 'Vertrieb Nord',
        'target_url' => 'https://hooks.example.test/in',
        'secret' => Crypt::encryptString('whsec_stored'),
        'status' => WebhookSubscriptionStatus::Active->value,
        'event_types' => '["record.created"]',
        'consecutive_failures' => 0,
        ...$attributes,
    ]);

    $this->teamLookup = fn (): ?array => collect($this->verifier->lookups)->first(static fn (array $lookup): bool => $lookup['table'] === 'teams');

    config()->set('webhooks.events', ['record.created']);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('binds a new subscription to a living team of the own tenant', function (): void {
    $result = $this->create->execute($this->tenant, ($this->input)(['team_id' => ModelStub::ulid('sales-team')]));

    expect($result['subscription']->team_id)->toBe(ModelStub::ulid('sales-team'))
        ->and(($this->teamLookup)())->toBe([
            'table' => 'teams',
            'column' => 'id',
            'value' => ModelStub::ulid('sales-team'),
            'extra' => ['tenant_id' => $this->tenantId, 'deleted_at' => 'NULL'],
        ]);
});

it('creates a system wide subscription when no team is chosen', function (): void {
    $result = $this->create->execute($this->tenant, ($this->input)(['team_id' => null]));

    expect($result['subscription']->team_id)->toBeNull();
});

it('refuses a team that is deleted or belongs to another tenant on the team field', function (string $verb): void {
    $this->verifier = ($this->presence)([]);
    $this->activate->shouldNotReceive('execute');

    $attempt = $verb === 'create'
        ? fn () => $this->create->execute($this->tenant, ($this->input)(['team_id' => ModelStub::ulid('foreign-team')]))
        : fn () => $this->update->execute(($this->subscription)(), ($this->input)(['team_id' => ModelStub::ulid('foreign-team')]));

    try {
        $attempt();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['team_id'])
            ->and($exception->errors()['team_id'][0])->not->toStartWith('validation.')
            ->and($this->connection->writtenStatements)->toBe([]);

        return;
    }

    throw new RuntimeException('A team outside the tenant was accepted.');
})->with(['create', 'update']);

it('moves an existing subscription to another team and back to the whole system', function (): void {
    $subscription = ($this->subscription)(['team_id' => ModelStub::ulid('sales-team')]);

    $this->update->execute($subscription, ($this->input)(['team_id' => ModelStub::ulid('service-team')]));

    expect($subscription->team_id)->toBe(ModelStub::ulid('service-team'))
        ->and($this->verifier->wasLookedUpWithin('teams', $this->tenantId))->toBeTrue();

    $this->update->execute($subscription, ($this->input)(['team_id' => null]));

    expect($subscription->team_id)->toBeNull();
});
