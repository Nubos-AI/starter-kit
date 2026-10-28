<?php

declare(strict_types=1);

use App\Actions\Activities\SaveRecordActivityAction;
use App\Models\CustomRecord;
use App\Models\RecordActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->recordTenantId = ModelStub::ulid('owning-tenant');
    $this->actor = AccessContext::actAs(AccessContext::user($this->tenant));
    $this->action = app(SaveRecordActivityAction::class);
    $this->activityTypeId = ModelStub::ulid('activity-type');

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('activity-record'),
        'tenant_id' => $this->recordTenantId,
    ]);

    $this->trashedRecord = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('trashed-activity-record'),
        'tenant_id' => $this->recordTenantId,
        'deleted_at' => '2026-09-01 00:00:00',
    ]);

    $this->input = [
        'subject' => 'Rückruf',
        'occurred_at' => '2026-09-19T10:00:00+02:00',
        'activity_type_id' => $this->activityTypeId,
        'assignee_id' => (string) $this->actor->getKey(),
    ];

    /** @var callable(array<string, mixed>, ?RecordActivity):?QueryShape */
    $this->lookupFor = fn (array $input, ?RecordActivity $activity = null): ?QueryShape => QueryShape::attemptedBy(
        fn (): mixed => $this->action->execute($this->actor, $this->record, $input, $activity),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks the policy for the record before it reads or validates anything', function (): void {
    $gate = GateSpy::allowing();

    expect(fn (): mixed => $this->action->execute($this->actor, $this->record, $this->input))
        ->toThrow(AuthorizationException::class)
        ->and($gate->calls[0]['ability'])->toBe('update')
        ->and($gate->calls[0]['arguments'][0])->toBe($this->record);
});

it('reads nothing at all when the policy refuses', function (): void {
    GateSpy::allowing();

    expect(QueryShape::attemptedBy(function (): void {
        try {
            $this->action->execute($this->actor, $this->record, $this->input);
        } catch (AuthorizationException) {
            return;
        }

        $this->fail('the action accepted a write the policy should have refused');
    }))->toBeNull();
});

it('refuses to add an activity to a record that is in the trash', function (): void {
    GateSpy::allowing('update');

    expect(fn (): mixed => $this->action->execute($this->actor, $this->trashedRecord, $this->input))
        ->toThrow(NotFoundHttpException::class);
});

it('refuses to move an existing activity onto a different record', function (): void {
    GateSpy::allowing('update');

    $foreign = ModelStub::make(RecordActivity::class, [
        'record_id' => ModelStub::ulid('some-other-record'),
        'activity_type_id' => $this->activityTypeId,
    ]);

    expect(fn (): mixed => $this->action->execute($this->actor, $this->record, $this->input, $foreign))
        ->toThrow(NotFoundHttpException::class);
});

it('accepts an activity type only from the tenant the record belongs to, not the bound one', function (): void {
    GateSpy::allowing('update');

    $shape = ($this->lookupFor)($this->input);

    expect($shape)->not->toBeNull()
        ->and($shape->targets('activity_types'))->toBeTrue()
        ->and($shape->hasBinding($this->activityTypeId))->toBeTrue()
        ->and($shape->hasBinding($this->recordTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('hides a retired activity type from a new activity', function (): void {
    GateSpy::allowing('update');

    expect(($this->lookupFor)($this->input)->sql)->toContain('"deleted_at" is null');
});

it('keeps a retired activity type usable on the activity that already carries it', function (): void {
    GateSpy::allowing('update');

    $existing = ModelStub::make(RecordActivity::class, [
        'record_id' => $this->record->getKey(),
        'activity_type_id' => $this->activityTypeId,
    ]);

    expect(($this->lookupFor)($this->input, $existing)->sql)->not->toContain('"deleted_at" is null');
});

it('accepts an assignee only from the tenant the record belongs to and only while the account lives', function (): void {
    GateSpy::allowing('update');

    $shape = ($this->lookupFor)([...$this->input, 'activity_type_id' => ['not a string']]);

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->actor->getKey()))->toBeTrue()
        ->and($shape->hasBinding($this->recordTenantId))->toBeTrue()
        ->and($shape->sql)->toContain('"deleted_at" is null');
});

it('refuses an activity without a subject, without a date, or with an unusable date', function (): void {
    GateSpy::allowing('update');

    /** @var callable(array<string, mixed>):array<string, list<string>> */
    $errorsOf = function (array $input): array {
        try {
            $this->action->execute($this->actor, $this->record, $input);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted an activity it should have refused');
    };

    $bare = $errorsOf(['activity_type_id' => ['skip the lookup']]);

    expect($bare)->toHaveKey('subject')
        ->and($bare)->toHaveKey('occurred_at')
        ->and($bare)->toHaveKey('assignee_id')
        ->and($errorsOf([
            'activity_type_id' => ['skip the lookup'],
            'subject' => str_repeat('a', 256),
            'occurred_at' => 'not a date',
        ]))->toHaveKeys(['subject', 'occurred_at']);
});
