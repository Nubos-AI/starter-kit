<?php

declare(strict_types=1);

use App\Contracts\Webhooks\WebhookDeliveryWorkflowInterface;
use App\DTOs\Webhooks\WebhookDeliveryData;
use App\Enums\Webhooks\WebhookEventType;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\WebhookSubscription;
use App\Support\Webhooks\WebhookDeliveryStarter;
use App\Support\Webhooks\WebhookPayloadBuilder;
use Carbon\CarbonImmutable;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Exception\Client\WorkflowExecutionAlreadyStartedException;
use Temporal\Workflow\WorkflowExecution;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('deals'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('deal'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'version' => 2,
    ], ['objectType' => $this->objectType]);

    $this->subscription = ModelStub::make(WebhookSubscription::class, [
        'id' => ModelStub::ulid('subscription'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ]);

    $this->occurredAt = CarbonImmutable::parse('2026-09-01 08:00:00', 'UTC');

    $this->payloadBuilder = Mockery::mock(WebhookPayloadBuilder::class);
    $this->payloadBuilder->shouldReceive('build')->andReturn(['event' => 'record.updated']);

    $this->started = [];
    $this->workflowIds = [];

    $this->workflowClient = Mockery::mock(WorkflowClientInterface::class);
    $this->workflowClient->shouldReceive('newWorkflowStub')
        ->andReturnUsing(function (string $workflow, ?WorkflowOptions $options = null): object {
            $stub = new stdClass;

            $this->workflowIds[spl_object_id($stub)] = [$workflow, (string) $options?->workflowId, (string) $options?->taskQueue];

            return $stub;
        });

    $this->starter = new WebhookDeliveryStarter($this->workflowClient, $this->payloadBuilder);

    /** @var callable(?Throwable):void */
    $this->acceptStarts = function (?Throwable $failure = null): void {
        $this->workflowClient->shouldReceive('start')
            ->andReturnUsing(function (object $stub, WebhookDeliveryData $input) use ($failure): WorkflowRunInterface {
                if ($failure instanceof Throwable) {
                    throw $failure;
                }

                $this->started[] = [$this->workflowIds[spl_object_id($stub)], $input];

                return Mockery::mock(WorkflowRunInterface::class);
            });
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('keys the delivery workflow to the subscription, the event type and the sequence', function (): void {
    ($this->acceptStarts)();

    $this->starter->start(
        $this->subscription,
        $this->record,
        WebhookEventType::RecordUpdated,
        42,
        $this->occurredAt,
        ModelStub::ulid('event'),
    );

    [$options] = $this->started[0];

    expect($options[0])->toBe(WebhookDeliveryWorkflowInterface::class)
        ->and($options[1])->toBe("webhook-delivery-{$this->subscription->getKey()}-record.updated-42")
        ->and($options[2])->toBe((string) config('temporal.queue'));
});

it('hands the delivery the subscription, the tenant, the event and the rendered body', function (): void {
    ($this->acceptStarts)();

    $this->starter->start(
        $this->subscription,
        $this->record,
        WebhookEventType::RecordUpdated,
        42,
        $this->occurredAt,
        ModelStub::ulid('event'),
    );

    [, $input] = $this->started[0];

    expect($input->subscriptionId)->toBe((string) $this->subscription->getKey())
        ->and($input->tenantId)->toBe((string) $this->tenant->getKey())
        ->and($input->eventId)->toBe(ModelStub::ulid('event'))
        ->and($input->eventType)->toBe('record.updated')
        ->and($input->sequence)->toBe(42)
        ->and($input->rawBody)->toBe('{"event":"record.updated"}');
});

it('treats an already started delivery as a silent no-op', function (): void {
    ($this->acceptStarts)(new WorkflowExecutionAlreadyStartedException(new WorkflowExecution('webhook-delivery-x')));

    $this->starter->start(
        $this->subscription,
        $this->record,
        WebhookEventType::RecordUpdated,
        42,
        $this->occurredAt,
        ModelStub::ulid('event'),
    );

    expect($this->started)->toBe([]);
});

it('lets any other delivery failure through so the caller can log it', function (): void {
    ($this->acceptStarts)(new RuntimeException('temporal ist weg'));

    expect(fn (): null => $this->starter->start(
        $this->subscription,
        $this->record,
        WebhookEventType::RecordUpdated,
        42,
        $this->occurredAt,
        ModelStub::ulid('event'),
    ) ?? null)->toThrow(RuntimeException::class, 'temporal ist weg');
});
