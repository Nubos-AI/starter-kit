<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\ReminderTask;
use App\Support\Reminders\ReminderAuthority;
use App\Support\Reminders\ReminderRecordSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticReminderRecordSource;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('reminder-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('reminder-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('reminder-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->allowViewFor = static function (string ...$userIds): void {
        $allowed = array_values($userIds);

        Gate::before(static function (?Authenticatable $user, string $ability) use ($allowed): ?bool {
            if ($ability !== 'view') {
                return null;
            }

            return in_array((string) $user?->getAuthIdentifier(), $allowed, true);
        });
    };

    $this->authorityWith = static fn (ReminderRecordSource $source): ReminderAuthority => new ReminderAuthority($source);

    $this->sourceKnowing = fn (): StaticReminderRecordSource => new StaticReminderRecordSource([
        (string) $this->record->getKey() => $this->record,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('links a record the acting user may view', function (): void {
    $user = AccessContext::user($this->tenant, [], 'reminder-viewer');
    ($this->allowViewFor)((string) $user->getKey());

    $source = ($this->sourceKnowing)();

    ($this->authorityWith)($source)->assertMayLinkRecord($user, (string) $this->record->getKey());

    expect($source->askedFor)->toBe([(string) $this->record->getKey()]);
});

it('refuses to link a record the acting user may not view', function (): void {
    $user = AccessContext::user($this->tenant, [], 'reminder-blind');
    ($this->allowViewFor)();

    try {
        ($this->authorityWith)(($this->sourceKnowing)())->assertMayLinkRecord($user, (string) $this->record->getKey());
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['record_id']);

        return;
    }

    $this->fail('a reminder was linked to a record the user may not view');
});

it('refuses to link a record that does not exist for the acting user', function (): void {
    $user = AccessContext::user($this->tenant, [], 'reminder-viewer');
    ($this->allowViewFor)((string) $user->getKey());

    expect(fn () => ($this->authorityWith)(new StaticReminderRecordSource)
        ->assertMayLinkRecord($user, ModelStub::ulid('unknown-record')))
        ->toThrow(ValidationException::class);
});

it('asks for no record at all when the reminder stays unlinked', function (): void {
    $user = AccessContext::user($this->tenant, [], 'reminder-viewer');
    $source = new StaticReminderRecordSource;

    ($this->authorityWith)($source)->assertMayLinkRecord($user, null);
    ($this->authorityWith)($source)->assertMayLinkRecord($user, '');
    ($this->authorityWith)($source)->assertMayLinkRecord($user, ['nested']);

    expect($source->askedFor)->toBe([]);
});

it('checks nothing for a background write without an acting user', function (): void {
    $source = ($this->sourceKnowing)();

    ($this->authorityWith)($source)->assertMayLinkRecord(null, (string) $this->record->getKey());

    expect($source->askedFor)->toBe([]);
});

it('looks the linked record up inside the bound tenant with its object type at hand', function (): void {
    $recordId = ModelStub::ulid('reminder-record');

    $attempt = QueryShape::attemptedBy(fn (): ?CustomRecord => (new ReminderRecordSource)->find($recordId));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('custom_records'))->toBeTrue()
        ->and($attempt?->isKeyedTo('custom_records', $recordId))->toBeTrue()
        ->and($attempt?->isScopedToTenant('custom_records', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hidesSoftDeleted('custom_records'))->toBeTrue();
});

it('lets the owner, the creator and the assignee of a reminder manage it and nobody else', function (): void {
    $owner = AccessContext::user($this->tenant, [], 'reminder-owner');
    $creator = AccessContext::user($this->tenant, [], 'reminder-creator');
    $assignee = AccessContext::user($this->tenant, [], 'reminder-assignee');
    $stranger = AccessContext::user($this->tenant, [], 'reminder-stranger');

    $reminder = ModelStub::make(ReminderTask::class, [
        'id' => ModelStub::ulid('reminder-task'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => $owner->getKey(),
        'creator_id' => $creator->getKey(),
        'assignee_id' => $assignee->getKey(),
    ]);

    $authority = ($this->authorityWith)(new StaticReminderRecordSource);

    expect($authority->manages($owner, $reminder))->toBeTrue()
        ->and($authority->manages($creator, $reminder))->toBeTrue()
        ->and($authority->manages($assignee, $reminder))->toBeTrue()
        ->and($authority->manages($stranger, $reminder))->toBeFalse();
});

it('keeps a reminder without an assignee out of the reach of a stranger', function (): void {
    $stranger = AccessContext::user($this->tenant, [], 'reminder-stranger');

    $reminder = ModelStub::make(ReminderTask::class, [
        'id' => ModelStub::ulid('reminder-task'),
        'tenant_id' => $this->tenant->getKey(),
        'owner_id' => ModelStub::ulid('reminder-owner-id'),
        'creator_id' => ModelStub::ulid('reminder-creator-id'),
        'assignee_id' => null,
    ]);

    expect(($this->authorityWith)(new StaticReminderRecordSource)->manages($stranger, $reminder))->toBeFalse();
});
