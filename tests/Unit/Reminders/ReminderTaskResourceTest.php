<?php

declare(strict_types=1);

use App\Enums\Authorization\RoleAuthority;
use App\Http\Resources\ReminderTaskResource;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\ReminderTask;
use App\Models\ReminderType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('reminder-resource-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('reminder-resource-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->recordWith = fn (?string $deletedAt): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('reminder-resource-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'record_number' => 'C-42',
        'deleted_at' => $deletedAt,
    ], ['objectType' => $this->objectType]);

    $this->reminderWith = function (?CustomRecord $record): ReminderTask {
        $owner = ModelStub::make(User::class, ['id' => ModelStub::ulid('reminder-resource-owner'), 'name' => 'Owner']);
        $assignee = ModelStub::make(User::class, ['id' => ModelStub::ulid('reminder-resource-assignee'), 'name' => 'Assignee']);
        $type = ModelStub::make(ReminderType::class, ['id' => ModelStub::ulid('reminder-resource-kind'), 'name' => 'Rückruf']);

        return ModelStub::make(ReminderTask::class, [
            'id' => ModelStub::ulid('reminder-resource-task'),
            'tenant_id' => $this->tenant->getKey(),
            'subject' => 'Call back',
            'note' => null,
            'due_at' => '2026-09-23 08:00:00',
            'done_at' => null,
        ], [
            'owner' => $owner,
            'assignee' => $assignee,
            'reminderType' => $type,
            'record' => $record,
        ]);
    };

    $this->requestBy = static function (?User $user): Request {
        $request = Request::create('/reminders', 'GET');
        $request->setUserResolver(static fn (): ?User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('exposes only the reminder surface and never a raw internal column', function (): void {
    $payload = (new ReminderTaskResource(($this->reminderWith)(($this->recordWith)(null))))
        ->toArray(($this->requestBy)(null));

    expect(array_keys($payload))->toBe(['id', 'subject', 'note', 'type', 'dueAt', 'doneAt', 'recurrence', 'owner', 'assignee', 'record', 'links'])
        ->and($payload['recurrence'])->toBeNull()
        ->and($payload['links'])->toBe([])
        ->and($payload['dueAt'])->toBe('2026-09-23T08:00:00.000000Z')
        ->and($payload['record'])->toBe([
            'id' => ModelStub::ulid('reminder-resource-record'),
            'label' => 'C-42',
            'deleted' => false,
        ]);
});

it('hands out the repetition of a series reminder with its frequency, interval and end', function (): void {
    $reminder = ($this->reminderWith)(null);
    $reminder->setRawAttributes([...$reminder->getAttributes(), 'recurrence_frequency' => 'monthly', 'recurrence_interval' => 3, 'recurrence_ends_at' => '2027-03-31 21:59:59']);

    expect((new ReminderTaskResource($reminder))->toArray(($this->requestBy)(null))['recurrence'])
        ->toBe(['frequency' => 'monthly', 'interval' => 3, 'endsAt' => '2027-03-31T21:59:59.000000Z']);
});

it('hides the link to a deleted record from a user without escalated authority', function (): void {
    $plain = RoleHolder::make(['tenant_id' => $this->tenant->getKey()], [], 'reminder-resource-plain');

    $payload = (new ReminderTaskResource(($this->reminderWith)(($this->recordWith)('2026-09-01 00:00:00'))))
        ->toArray(($this->requestBy)($plain));

    expect($payload['record'])->toBeNull();
});

it('hides the link to a deleted record from an unauthenticated reader as well', function (): void {
    $payload = (new ReminderTaskResource(($this->reminderWith)(($this->recordWith)('2026-09-01 00:00:00'))))
        ->toArray(($this->requestBy)(null));

    expect($payload['record'])->toBeNull();
});

it('shows a deleted record to an escalated authority and flags it as deleted', function (): void {
    $admin = RoleHolder::make(
        ['tenant_id' => $this->tenant->getKey()],
        [ModelStub::make(Role::class, [
            'id' => ModelStub::ulid('reminder-resource-role'),
            'tenant_id' => $this->tenant->getKey(),
            'authority' => RoleAuthority::SuperAdmin->value,
        ])],
        'reminder-resource-admin',
    );

    $payload = (new ReminderTaskResource(($this->reminderWith)(($this->recordWith)('2026-09-01 00:00:00'))))
        ->toArray(($this->requestBy)($admin));

    expect($payload['record'])->toBe([
        'id' => ModelStub::ulid('reminder-resource-record'),
        'label' => 'C-42',
        'deleted' => true,
    ]);
});

it('leaves the record reference out of a standalone reminder', function (): void {
    $payload = (new ReminderTaskResource(($this->reminderWith)(null)))->toArray(($this->requestBy)(null));

    expect($payload['record'])->toBeNull()
        ->and($payload['type'])->toBe(['id' => ModelStub::ulid('reminder-resource-kind'), 'label' => 'Rückruf']);
});

it('hands out the pre-resolved quick links of exactly its own reminder', function (): void {
    $reminder = ($this->reminderWith)(null);
    $link = ['key' => 'email_message', 'id' => ModelStub::ulid('reminder-resource-mail'), 'label' => 'E-Mail: Angebot', 'href' => '/mails/1'];

    $payload = ReminderTaskResource::withLinksFrom($reminder, [
        (string) $reminder->getKey() => [$link],
        ModelStub::ulid('reminder-resource-other') => [['key' => 'record', 'id' => 'x', 'label' => 'C-9', 'href' => '/records/x']],
    ])->toArray(($this->requestBy)(null));

    expect($payload['links'])->toBe([$link]);
});
