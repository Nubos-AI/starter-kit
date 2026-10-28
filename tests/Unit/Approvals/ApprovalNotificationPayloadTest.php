<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalEventType;
use App\Models\ApprovalEvent;
use App\Models\ApprovalProcess;
use App\Models\ApprovalProcessStage;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Notifications\Approvals\ApprovalDecidedNotification;
use App\Notifications\Approvals\ApprovalEscalatedNotification;
use App\Notifications\Approvals\ApprovalRequestedNotification;
use App\Support\Approvals\ApprovalRecordContext;
use App\Support\Approvals\ApprovalSubjectSource;
use App\Support\Notifications\ChannelPreferenceResolver;
use App\Support\Teams\TeamSegment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->recipient = AccessContext::user($this->tenant, [], 'recipient');

    URL::defaults([TeamSegment::key() => 'personal']);

    $this->fieldValue = 'Vertraulicher-Feldwert-98765';

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('approval-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('object-type'),
        'record_number' => 'REC-4711',
        'owner_id' => ModelStub::ulid('record-owner'),
        'version' => 7,
        'data' => ['salary' => $this->fieldValue, 'margin' => 42.5],
    ], [
        'objectType' => ModelStub::make(ObjectType::class, [
            'id' => ModelStub::ulid('object-type'),
            'tenant_id' => $this->tenant->getKey(),
            'name' => 'Deal',
        ]),
    ]);

    $this->process = ModelStub::make(ApprovalProcess::class, [
        'id' => ModelStub::ulid('process'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $this->record->getKey(),
        'approval_definition_id' => ModelStub::ulid('definition'),
        'anchor_type' => 'Nubos\\Pipelines\\Models\\StageTransition',
        'anchor_id' => ModelStub::ulid('anchor'),
        'triggered_by_id' => ModelStub::ulid('trigger'),
        'attempt' => 1,
        'current_stage_position' => 1,
        'record_version' => 7,
    ], ['record' => $this->record]);

    $this->stage = ModelStub::make(ApprovalProcessStage::class, [
        'id' => ModelStub::ulid('process-stage'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'approval_definition_stage_id' => ModelStub::ulid('definition-stage'),
        'position' => 2,
        'attempt' => 1,
        'deadline_at' => Carbon::parse('2026-03-04 12:30:00', 'UTC'),
    ]);

    $this->event = ModelStub::make(ApprovalEvent::class, [
        'id' => ModelStub::ulid('event'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'approval_process_stage_id' => $this->stage->getKey(),
        'actor_id' => ModelStub::ulid('deputy-head'),
        'on_behalf_of_id' => ModelStub::ulid('head'),
        'type' => ApprovalEventType::Approved,
        'reason' => 'Budget passt.',
        'occurred_at' => Carbon::parse('2026-03-03 09:00:00', 'UTC'),
    ]);

    $this->subjects = Mockery::mock(ApprovalSubjectSource::class);
    $this->subjects->shouldReceive('processWithRecord')->andReturn($this->process);
    $this->subjects->shouldReceive('displayName')->andReturnUsing(
        fn (string $tenantId, string $userId): ?string => match ($userId) {
            ModelStub::ulid('deputy-head') => 'Dana Vertretung',
            ModelStub::ulid('head') => 'Hans Haupt',
            default => null,
        },
    );
    app()->instance(ApprovalSubjectSource::class, $this->subjects);

    $this->recordContext = Mockery::mock(ApprovalRecordContext::class);
    $this->recordContext->shouldReceive('stageLabel')->andReturnUsing(
        static fn (?string $id): ?string => $id === null ? null : 'Stufe '.substr($id, 0, 4),
    );
    app()->instance(ApprovalRecordContext::class, $this->recordContext);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('carries exactly the agreed keys in the payload of an approval request', function (): void {
    $payload = (new ApprovalRequestedNotification($this->stage))->toInbox($this->recipient);

    expect(array_keys($payload))->toEqualCanonicalizing([
        'title',
        'body',
        'approvalProcessId',
        'recordId',
        'recordNumber',
        'url',
        'approvalProcessStageId',
        'stagePosition',
        'fromStage',
        'toStage',
        'deadlineAt',
    ]);
});

it('never puts a raw field value of the record into the payload of an approval request', function (): void {
    $payload = (new ApprovalRequestedNotification($this->stage))->toInbox($this->recipient);

    expect(json_encode($payload))->not->toContain($this->fieldValue)
        ->and(json_encode($payload))->not->toContain('42.5')
        ->and(json_encode($payload))->not->toContain('salary');
});

it('names the record by its number and the stage by its label in an approval request', function (): void {
    $payload = (new ApprovalRequestedNotification($this->stage))->toInbox($this->recipient);

    expect($payload['recordNumber'])->toBe('REC-4711')
        ->and($payload['recordId'])->toBe($this->record->getKey())
        ->and($payload['stagePosition'])->toBe(2)
        ->and($payload['deadlineAt'])->toBe('2026-03-04T12:30:00.000000Z')
        ->and($payload['body'])->toContain('REC-4711');
});

it('never puts a raw field value of the record into the payload of a decision', function (): void {
    $payload = (new ApprovalDecidedNotification($this->event))->toInbox($this->recipient);

    expect(array_keys($payload))->toEqualCanonicalizing([
        'title',
        'body',
        'approvalProcessId',
        'recordId',
        'recordNumber',
        'url',
        'approvalEventId',
        'outcome',
        'outcomeLabel',
        'decidedBy',
        'onBehalfOf',
        'reason',
    ])
        ->and(json_encode($payload))->not->toContain($this->fieldValue)
        ->and(json_encode($payload))->not->toContain('salary');
});

it('names the deputy and the represented person in a decision', function (): void {
    $payload = (new ApprovalDecidedNotification($this->event))->toInbox($this->recipient);

    expect($payload['decidedBy'])->toBe('Dana Vertretung')
        ->and($payload['onBehalfOf'])->toBe('Hans Haupt')
        ->and($payload['outcome'])->toBe(ApprovalEventType::Approved->value)
        ->and($payload['reason'])->toBe('Budget passt.')
        ->and($payload['body'])->toContain('Dana Vertretung')
        ->and($payload['body'])->toContain('Hans Haupt');
});

it('leaves an unknown actor unnamed instead of exposing an identifier', function (): void {
    $anonymous = ModelStub::make(ApprovalEvent::class, [
        'id' => ModelStub::ulid('anonymous-event'),
        'tenant_id' => $this->tenant->getKey(),
        'approval_process_id' => $this->process->getKey(),
        'actor_id' => ModelStub::ulid('vanished'),
        'on_behalf_of_id' => null,
        'type' => ApprovalEventType::Rejected,
        'occurred_at' => Carbon::parse('2026-03-03 09:00:00', 'UTC'),
    ]);

    $payload = (new ApprovalDecidedNotification($anonymous))->toInbox($this->recipient);

    expect($payload['decidedBy'])->toBeNull()
        ->and($payload['onBehalfOf'])->toBeNull()
        ->and($payload['body'])->not->toContain(ModelStub::ulid('vanished'));
});

it('never puts a raw field value of the record into the payload of an escalation', function (): void {
    $payload = (new ApprovalEscalatedNotification($this->stage, ApprovalEscalationType::WidenCircle))->toInbox($this->recipient);

    expect(array_keys($payload))->toEqualCanonicalizing([
        'title',
        'body',
        'approvalProcessId',
        'recordId',
        'recordNumber',
        'url',
        'approvalProcessStageId',
        'stagePosition',
        'escalationType',
        'escalationLabel',
        'deadlineAt',
    ])
        ->and(json_encode($payload))->not->toContain($this->fieldValue)
        ->and($payload['escalationType'])->toBe(ApprovalEscalationType::WidenCircle->value);
});

it('leaves the channel choice of every approval notification to the preferences of the recipient', function (): void {
    $asked = [];

    $resolver = Mockery::mock(ChannelPreferenceResolver::class);
    $resolver->shouldReceive('channelsFor')
        ->andReturnUsing(function (mixed $notifiable, string $type) use (&$asked): array {
            $asked[] = $type;

            return ['database'];
        });
    app()->instance(ChannelPreferenceResolver::class, $resolver);

    $notifications = [
        new ApprovalRequestedNotification($this->stage),
        new ApprovalDecidedNotification($this->event),
        new ApprovalEscalatedNotification($this->stage, ApprovalEscalationType::NotifyAgain),
    ];

    foreach ($notifications as $notification) {
        expect($notification->via($this->recipient))->toBe(['database']);
    }

    expect($asked)->toBe(['approval.requested', 'approval.decided', 'approval.escalated']);
});
