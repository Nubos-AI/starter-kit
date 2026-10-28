<?php

declare(strict_types=1);

use App\Actions\Records\SyncRecordWatchersAction;
use App\Actions\Records\SyncRecordWatcherSourceAction;
use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordWatcher;
use App\Support\Watchers\WatcherAutoSubscriber;
use App\Support\Watchers\WatcherEligibility;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('watcher-sync-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('watcher-sync-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('watcher-sync-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->eligibility = new class extends WatcherEligibility
    {
        /**
         * @var list<array{ids: list<string>, field: string}>
         */
        public array $asked = [];

        public bool $refuses = false;

        public function __construct() {}

        public function assertMayWatch(CustomRecord $record, array $userIds, string $field, string $message): void
        {
            $this->asked[] = ['ids' => $userIds, 'field' => $field];

            if ($this->refuses) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    };

    $this->subscriber = new class extends WatcherAutoSubscriber
    {
        /**
         * @var list<string>
         */
        public array $subscribed = [];

        public function __construct() {}

        public function subscribe(string $recordId, string $userId, WatcherSource $source, ?string $addedById = null): RecordWatcher
        {
            $this->subscribed[] = $userId;

            return new RecordWatcher;
        }
    };

    $this->sourceAction = fn (): SyncRecordWatcherSourceAction => new SyncRecordWatcherSourceAction($this->subscriber, $this->eligibility);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks the eligibility guard before it writes a single watcher row', function (): void {
    $this->eligibility->refuses = true;
    $watcherId = ModelStub::ulid('watcher-sync-user');

    expect(fn () => ($this->sourceAction)()->execute(
        $this->record,
        ['user_ids' => [$watcherId]],
        'user_ids',
        WatcherSource::Manual,
        'refused',
    ))->toThrow(ValidationException::class)
        ->and($this->eligibility->asked)->toBe([['ids' => [$watcherId], 'field' => 'user_ids']])
        ->and($this->subscriber->subscribed)->toBe([]);
});

it('hands each named user to the eligibility guard exactly once', function (): void {
    $watcherId = ModelStub::ulid('watcher-sync-user');

    WriteAttempt::reachedTheDatabase(fn () => ($this->sourceAction)()->execute(
        $this->record,
        ['user_ids' => [$watcherId, $watcherId]],
        'user_ids',
        WatcherSource::Manual,
        'refused',
    ));

    expect($this->eligibility->asked)->toBe([['ids' => [$watcherId], 'field' => 'user_ids']]);
});

it('refuses a watcher list that is no list of identifiers', function (): void {
    expect(fn () => ($this->sourceAction)()->execute(
        $this->record,
        ['user_ids' => ['not-a-ulid']],
        'user_ids',
        WatcherSource::Manual,
        'refused',
    ))->toThrow(ValidationException::class)
        ->and(fn () => ($this->sourceAction)()->execute(
            $this->record,
            [],
            'user_ids',
            WatcherSource::Manual,
            'refused',
        ))->toThrow(ValidationException::class)
        ->and($this->eligibility->asked)->toBe([]);
});

it('still asks the guard for an empty list so a clearing sync stays a checked write', function (): void {
    WriteAttempt::reachedTheDatabase(fn () => ($this->sourceAction)()->execute(
        $this->record,
        ['user_ids' => []],
        'user_ids',
        WatcherSource::Manual,
        'refused',
    ));

    expect($this->eligibility->asked)->toBe([['ids' => [], 'field' => 'user_ids']]);
});

it('syncs the manually managed watchers under the user_ids field', function (): void {
    $watcherId = ModelStub::ulid('watcher-sync-user');
    $this->eligibility->refuses = true;

    expect(fn () => (new SyncRecordWatchersAction(($this->sourceAction)()))->execute($this->record, ['user_ids' => [$watcherId]]))
        ->toThrow(ValidationException::class)
        ->and($this->eligibility->asked)->toBe([['ids' => [$watcherId], 'field' => 'user_ids']]);
});
