<?php

declare(strict_types=1);

use App\Enums\Watchers\WatcherSource;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\RecordWatcher;
use App\Support\Watchers\WatcherAutoSubscriber;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('watcher-auto-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('watcher-auto-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->recordWith = fn (?string $ownerId): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('watcher-auto-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'owner_id' => $ownerId,
    ], ['objectType' => $this->objectType]);

    $this->subscriber = new class extends WatcherAutoSubscriber
    {
        /**
         * @var list<array{user: string, source: WatcherSource}>
         */
        public array $subscribed = [];

        public function subscribe(string $recordId, string $userId, WatcherSource $source, ?string $addedById = null): RecordWatcher
        {
            $this->subscribed[] = ['user' => $userId, 'source' => $source];

            return new RecordWatcher;
        }
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('subscribes nobody on a system write that has no acting user, not even the owner', function (): void {
    $ownerId = ModelStub::ulid('watcher-auto-owner');

    $this->subscriber->onRecordCreated(($this->recordWith)($ownerId));

    expect($this->subscriber->subscribed)->toBe([]);
});

it('subscribes the creator and the assigned owner as automatic watchers', function (): void {
    $creator = AccessContext::actAs(AccessContext::user($this->tenant, [], 'watcher-auto-creator'));
    $ownerId = ModelStub::ulid('watcher-auto-owner');

    $this->subscriber->onRecordCreated(($this->recordWith)($ownerId));

    expect($this->subscriber->subscribed)->toBe([
        ['user' => (string) $creator->getKey(), 'source' => WatcherSource::Auto],
        ['user' => $ownerId, 'source' => WatcherSource::Auto],
    ]);
});

it('writes no watcher row for a record without an owner', function (): void {
    $creator = AccessContext::actAs(AccessContext::user($this->tenant, [], 'watcher-auto-creator'));

    $this->subscriber->onRecordCreated(($this->recordWith)(null));

    expect($this->subscriber->subscribed)->toBe([
        ['user' => (string) $creator->getKey(), 'source' => WatcherSource::Auto],
    ]);
});

it('subscribes a newly assigned owner and ignores an owner that was cleared', function (): void {
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'watcher-auto-creator'));
    $ownerId = ModelStub::ulid('watcher-auto-owner');

    $this->subscriber->onOwnerAssigned(ModelStub::ulid('watcher-auto-record'), $ownerId);
    $this->subscriber->onOwnerAssigned(ModelStub::ulid('watcher-auto-record'), null);

    expect($this->subscriber->subscribed)->toBe([
        ['user' => $ownerId, 'source' => WatcherSource::Auto],
    ]);
});

it('reads the tenant of a watched record across every scope so a background write still lands', function (): void {
    $recordId = ModelStub::ulid('watcher-auto-record');
    AccessContext::forgetTenant();

    $attempt = QueryShape::attemptedBy(fn () => (new WatcherAutoSubscriber)->subscribe($recordId, ModelStub::ulid('someone'), WatcherSource::Auto));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->blocksEveryRow())->toBeFalse();
});
