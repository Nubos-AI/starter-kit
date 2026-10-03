<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalEventType;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Models\User;
use App\Notifications\Approvals\ApprovalDecidedNotification;
use App\Notifications\Approvals\ApprovalEscalatedNotification;
use App\Notifications\Approvals\ApprovalRequestedNotification;
use App\Support\Approvals\ApprovalNotifier;
use App\Support\Approvals\ApprovalStageEligibility;
use App\Support\Approvals\ApprovalSubjectSource;
use App\Support\Notifications\ChannelPreferenceResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->otherTenantId = ModelStub::ulid('other-tenant');

    $this->headId = ModelStub::ulid('head');
    $this->clerkId = ModelStub::ulid('clerk');
    $this->triggerId = ModelStub::ulid('trigger');
    $this->ownerId = ModelStub::ulid('record-owner');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->ownerId,
        'record_number' => 'REC-4711',
    ]);

    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('process'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
        'approval_definition_id' => ModelStub::ulid('definition'),
        'anchor_type' => 'Nubos\\Pipelines\\Models\\StageTransition',
        'anchor_id' => ModelStub::ulid('anchor'),
        'triggered_by_id' => $this->triggerId,
        'attempt' => 1,
        'current_stage_position' => 1,
    ], ['record' => $this->record]);

    $this->stage = ModelStub::make(ApprovalProcessStage::class, [
        'id' => ModelStub::ulid('process-stage'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'approval_definition_stage_id' => ModelStub::ulid('definition-stage'),
        'position' => 1,
        'attempt' => 1,
    ]);

    $this->event = ModelStub::make(ApprovalEvent::class, [
        'id' => ModelStub::ulid('event'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'approval_process_stage_id' => $this->stage->getKey(),
        'actor_id' => $this->headId,
        'type' => ApprovalEventType::Approved,
        'occurred_at' => Carbon::parse('2026-03-03 09:00:00', 'UTC'),
    ]);

    $channels = Mockery::mock(ChannelPreferenceResolver::class);
    $channels->shouldReceive('channelsFor')->andReturn(['database']);
    app()->instance(ChannelPreferenceResolver::class, $channels);

    $this->eligibility = Mockery::mock(ApprovalStageEligibility::class);
    $this->subjects = Mockery::mock(ApprovalSubjectSource::class);
    $this->subjects->shouldReceive('processWithRecord')->andReturn($this->process);
    app()->instance(ApprovalSubjectSource::class, $this->subjects);

    $this->notifier = fn (): ApprovalNotifier => new ApprovalNotifier($this->eligibility, $this->subjects);

    $this->user = static fn (string $seed, string $tenantId): User => ModelStub::make(User::class, [
        'id' => ModelStub::ulid($seed),
        'tenant_id' => $tenantId,
        'name' => ucfirst($seed),
    ]);

    $this->asked = new ArrayObject;

    /**
     * @param  list<User>  $users
     */
    $this->recipientLookup = function (array $users): void {
        $this->subjects->shouldReceive('recipients')
            ->andReturnUsing(function (string $tenantId, array $recipientIds) use ($users): Collection {
                $this->asked->append([$tenantId, $recipientIds]);

                return new Collection($users);
            });
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('writes an approval request to exactly the people the eligibility names and to nobody else', function (): void {
    Notification::fake();

    $head = ($this->user)('head', $this->tenant->getKey());
    $clerk = ($this->user)('clerk', $this->tenant->getKey());
    $stranger = ($this->user)('stranger', $this->otherTenantId);

    $this->eligibility->shouldReceive('notificationRecipientIds')
        ->with($this->stage)
        ->andReturn([$this->headId, $this->clerkId]);

    ($this->recipientLookup)([$head, $clerk]);

    ($this->notifier)()->notifyStageStarted($this->stage);

    expect($this->asked->getArrayCopy())->toBe([[$this->tenant->getKey(), [$this->headId, $this->clerkId]]]);

    Notification::assertSentTo([$head, $clerk], ApprovalRequestedNotification::class);
    Notification::assertNotSentTo($stranger, ApprovalRequestedNotification::class);
});

it('asks for nobody and sends nothing when the eligible circle of the stage is empty', function (): void {
    Notification::fake();

    $this->eligibility->shouldReceive('notificationRecipientIds')->andReturn([]);

    ($this->recipientLookup)([]);

    ($this->notifier)()->notifyStageStarted($this->stage);

    expect($this->asked->getArrayCopy())->toBe([]);

    Notification::assertNothingSent();
});

it('writes an escalation only to the present eligible circle, never to the wider notification circle', function (): void {
    Notification::fake();

    $head = ($this->user)('head', $this->tenant->getKey());

    $this->eligibility->shouldReceive('eligibleUserIds')->with($this->stage)->andReturn([$this->headId]);
    $this->eligibility->shouldNotReceive('notificationRecipientIds');

    ($this->recipientLookup)([$head]);

    ($this->notifier)()->notifyEscalation($this->stage, ApprovalEscalationType::WidenCircle);

    expect($this->asked->getArrayCopy())->toBe([[$this->tenant->getKey(), [$this->headId]]]);

    Notification::assertSentTo($head, ApprovalEscalatedNotification::class);
});

it('writes a decision only to the person who triggered the process and to the owner of the record', function (): void {
    Notification::fake();

    $trigger = ($this->user)('trigger', $this->tenant->getKey());
    $owner = ($this->user)('record-owner', $this->tenant->getKey());

    $this->subjects->shouldReceive('process')->with($this->event)->andReturn($this->process);
    $this->subjects->shouldReceive('recordIfStillThere')->with($this->process)->andReturn($this->record);

    ($this->recipientLookup)([$trigger, $owner]);

    ($this->notifier)()->notifyDecision($this->event);

    expect($this->asked->getArrayCopy())->toBe([[$this->tenant->getKey(), [$this->triggerId, $this->ownerId]]]);

    Notification::assertSentTo([$trigger, $owner], ApprovalDecidedNotification::class);
});

it('names the trigger of a decision only once when they also own the record', function (): void {
    Notification::fake();

    $ownedByTrigger = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $this->triggerId,
    ]);

    $this->subjects->shouldReceive('process')->with($this->event)->andReturn($this->process);
    $this->subjects->shouldReceive('recordIfStillThere')->andReturn($ownedByTrigger);

    ($this->recipientLookup)([($this->user)('trigger', $this->tenant->getKey())]);

    ($this->notifier)()->notifyDecision($this->event);

    expect($this->asked->getArrayCopy())->toBe([[$this->tenant->getKey(), [$this->triggerId]]]);
});

it('sends nothing at all when the recipients cannot be resolved and says so in the log', function (): void {
    Notification::fake();
    Log::spy();

    $this->eligibility->shouldReceive('notificationRecipientIds')
        ->andThrow(new RuntimeException('Der Kandidatenkreis liess sich nicht aufloesen.'));

    ($this->notifier)()->notifyStageStarted($this->stage);

    Notification::assertNothingSent();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to prepare')
            && $context['approval_process_stage_id'] === (string) $this->stage->getKey());
});

it('still reaches the remaining recipients when the delivery to one of them fails', function (): void {
    Log::spy();

    $failing = Mockery::mock(User::class);
    $failing->shouldReceive('getKey')->andReturn($this->headId);
    $failing->shouldReceive('notify')->andThrow(new RuntimeException('Der Kanal ist nicht erreichbar.'));

    $delivered = [];

    $reached = Mockery::mock(User::class);
    $reached->shouldReceive('getKey')->andReturn($this->clerkId);
    $reached->shouldReceive('notify')->andReturnUsing(function (mixed $notification) use (&$delivered): void {
        $delivered[] = $notification::class;
    });

    $this->eligibility->shouldReceive('notificationRecipientIds')->andReturn([$this->headId, $this->clerkId]);
    ($this->recipientLookup)([$failing, $reached]);

    ($this->notifier)()->notifyStageStarted($this->stage);

    expect($delivered)->toBe([ApprovalRequestedNotification::class]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to deliver')
            && $context['recipient_id'] === $this->headId);
});
