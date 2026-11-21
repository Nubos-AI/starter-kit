<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\RecordActivity;
use App\Models\User;
use App\Policies\Activities\RecordActivityPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticSourceLinkResolver;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('activity-policy-tenant');
    $this->creator = AccessContext::user($this->tenant, [], 'activity-policy-creator');
    $this->assignee = AccessContext::user($this->tenant, [], 'activity-policy-assignee');
    $this->stranger = AccessContext::user($this->tenant, [], 'activity-policy-stranger');
    $this->foreigner = ModelStub::make(User::class, [
        'id' => $this->creator->getKey(),
        'tenant_id' => ModelStub::ulid('activity-policy-foreign-tenant'),
    ]);

    $this->mailId = ModelStub::ulid('activity-policy-mail');
    $this->mails = new StaticSourceLinkResolver('email_message', [], []);
    config(['links.sources.email_message' => StaticSourceLinkResolver::class]);
    app()->instance(StaticSourceLinkResolver::class, $this->mails);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('activity-policy-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);

    $this->activityWith = fn (array $attributes = [], array $relations = []): RecordActivity => ModelStub::make(RecordActivity::class, [
        'id' => ModelStub::ulid('activity-policy-activity'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => null,
        'creator_id' => $this->creator->getKey(),
        'assignee_id' => $this->assignee->getKey(),
        'source_type' => 'email_message',
        'source_id' => $this->mailId,
        ...$attributes,
    ], $relations);

    $this->recordGate = static function (array $abilities): void {
        Gate::before(static fn (?Authenticatable $user, string $ability, array $arguments = []): ?bool => ($arguments[0] ?? null) instanceof CustomRecord
            ? in_array($ability, $abilities, true)
            : null);
    };

    $this->policy = app(RecordActivityPolicy::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('lets creator and assignee see, change and delete a standalone activity', function (User $user): void {
    $activity = ($this->activityWith)();

    expect($this->policy->view($user, $activity))->toBeTrue()
        ->and($this->policy->update($user, $activity))->toBeTrue()
        ->and($this->policy->delete($user, $activity))->toBeTrue();
})->with([
    'creator' => fn (): User => $this->creator,
    'assignee' => fn (): User => $this->assignee,
]);

it('keeps a standalone activity away from a colleague who neither wrote it, holds it, nor may open its source', function (): void {
    $activity = ($this->activityWith)();

    expect($this->policy->view($this->stranger, $activity))->toBeFalse()
        ->and($this->policy->update($this->stranger, $activity))->toBeFalse()
        ->and($this->policy->delete($this->stranger, $activity))->toBeFalse()
        ->and($this->mails->accessChecks)->toBe([$this->mailId]);
});

it('shows a standalone activity to a colleague who may open its source but lets that colleague change nothing', function (): void {
    $mails = new StaticSourceLinkResolver('email_message', [], [$this->mailId]);
    app()->instance(StaticSourceLinkResolver::class, $mails);
    $activity = ($this->activityWith)();

    expect(app(RecordActivityPolicy::class)->view($this->stranger, $activity))->toBeTrue()
        ->and(app(RecordActivityPolicy::class)->update($this->stranger, $activity))->toBeFalse()
        ->and(app(RecordActivityPolicy::class)->delete($this->stranger, $activity))->toBeFalse();
});

it('hides a standalone activity whose source type no package registers any more from everyone but its participants', function (): void {
    $activity = ($this->activityWith)(['source_type' => 'uninstalled_source']);

    expect($this->policy->view($this->stranger, $activity))->toBeFalse()
        ->and($this->policy->view($this->creator, $activity))->toBeTrue();
});

it('refuses a standalone activity to its own creator id from another tenant', function (): void {
    $activity = ($this->activityWith)();

    expect($this->policy->view($this->foreigner, $activity))->toBeFalse()
        ->and($this->policy->update($this->foreigner, $activity))->toBeFalse();
});

it('decides about a record bound activity through the record alone', function (): void {
    ($this->recordGate)(['view']);
    $activity = ($this->activityWith)(['record_id' => $this->record->getKey()], ['record' => $this->record]);

    expect($this->policy->view($this->stranger, $activity))->toBeTrue()
        ->and($this->policy->update($this->creator, $activity))->toBeFalse()
        ->and($this->policy->delete($this->assignee, $activity))->toBeFalse();
});

it('lets whoever may change the record change its activities', function (): void {
    ($this->recordGate)(['view', 'update']);
    $activity = ($this->activityWith)(['record_id' => $this->record->getKey()], ['record' => $this->record]);

    expect($this->policy->update($this->stranger, $activity))->toBeTrue()
        ->and($this->policy->delete($this->stranger, $activity))->toBeTrue();
});

it('hides a record bound activity whose record the viewer may not open, even from its creator', function (): void {
    ($this->recordGate)([]);
    $activity = ($this->activityWith)(['record_id' => $this->record->getKey()], ['record' => $this->record]);

    expect($this->policy->view($this->creator, $activity))->toBeFalse();
});

it('lets every member of a tenant log a standalone activity and nobody without a tenant', function (): void {
    $tenantless = ModelStub::make(User::class, ['id' => ModelStub::ulid('activity-policy-tenantless'), 'tenant_id' => null]);

    expect($this->policy->create($this->stranger))->toBeTrue()
        ->and($this->policy->create($tenantless))->toBeFalse();
});
