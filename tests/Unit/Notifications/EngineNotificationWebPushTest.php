<?php

declare(strict_types=1);

use App\Models\PushSubscription;
use App\Models\ReminderTask;
use App\Notifications\Abstracts\EngineNotification;
use App\Notifications\ReminderDueNotification;
use App\Notifications\WebhookNotification;
use Illuminate\Database\Eloquent\Collection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\ReportHandlerInterface;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('web-push-tenant');

    $this->subscriber = AccessContext::user($this->tenant, ['current_team_id' => 'personal'], 'web-push-user');
    StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "teams"') ? [['id' => 'personal']] : []);
    $this->subscriber->setRelation('pushSubscriptions', new Collection([
        ModelStub::make(PushSubscription::class, [
            'id' => ModelStub::ulid('web-push-subscription'),
            'tenant_id' => $this->tenant->getKey(),
            'subscribable_type' => $this->subscriber->getMorphClass(),
            'subscribable_id' => $this->subscriber->getKey(),
            'endpoint' => 'https://push.example/endpoint',
            'public_key' => null,
            'auth_token' => null,
            'content_encoding' => null,
        ]),
    ]));

    $this->pushedThroughTheChannel = function (EngineNotification $notification): array {
        $payloads = [];
        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')
            ->once()
            ->andReturnUsing(static function (Subscription $subscription, ?string $payload) use (&$payloads): void {
                $payloads[$subscription->getEndpoint()] = json_decode((string) $payload, true);
            });
        $webPush->shouldReceive('flush')->once()->andReturnUsing(static fn (): Generator => yield from []);

        (new WebPushChannel($webPush, Mockery::mock(ReportHandlerInterface::class)))->send($this->subscriber, $notification);

        return $payloads;
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('delivers a notification to a subscribed user through the real push channel with the title and body the service worker shows', function (): void {
    $payloads = ($this->pushedThroughTheChannel)(new WebhookNotification(ModelStub::ulid('web-push-webhook'), 'https://hooks.example/in', 5));

    expect($payloads)->toBe([
        'https://push.example/endpoint' => [
            'title' => __('i18n.backend.notifications.webhook_notification.webhook_subscription_disabled'),
            'body' => __('i18n.backend.notifications.webhook_notification.delivery_to_has_failed_times_in_a_row_the', ['value1' => 'https://hooks.example', 'value2' => 5]),
        ],
    ]);
});

it('names only the scheme and host of a disabled webhook target and never its secret path or query', function (string $targetUrl, string $shown): void {
    $notification = new WebhookNotification(ModelStub::ulid('web-push-webhook'), $targetUrl, 5);

    $inbox = $notification->toInbox($this->subscriber);
    $pushed = json_encode(($this->pushedThroughTheChannel)($notification));

    expect($inbox['body'])->toContain($shown)
        ->and($inbox['body'])->not->toContain('s3cr3t')
        ->and($inbox['body'])->not->toContain('token=')
        ->and($pushed)->not->toContain('s3cr3t');
})->with([
    'secret in path and query' => ['https://hooks.example/in/s3cr3t?token=s3cr3t', 'https://hooks.example'],
    'credentials and port' => ['https://user:s3cr3t@hooks.example:8443/s3cr3t', 'https://hooks.example:8443'],
]);

it('hands the target of the notification to the service worker so a click opens it', function (): void {
    $recordId = ModelStub::ulid('web-push-record');
    $reminder = ModelStub::make(ReminderTask::class, [
        'id' => ModelStub::ulid('web-push-reminder'),
        'tenant_id' => $this->tenant->getKey(),
        'subject' => 'Call back',
        'record_id' => $recordId,
    ]);

    $payload = ($this->pushedThroughTheChannel)(new ReminderDueNotification($reminder))['https://push.example/endpoint'] ?? [];

    expect($payload['title'] ?? null)->toBe(__('i18n.backend.notifications.reminder_due_notification.title', ['value1' => 'Call back']))
        ->and($payload['data'] ?? null)->toBe(['url' => route('engine.records.show', ['activeTeam' => 'personal', 'record' => $recordId])]);
});
