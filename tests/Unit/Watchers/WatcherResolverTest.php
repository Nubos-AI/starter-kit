<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Watchers\WatcherDirectory;
use App\Support\Watchers\WatcherResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticWatcherDirectory;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('watcher-resolver-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('watcher-resolver-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->recordWith = fn (?string $ownerId): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('watched-resolver-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'owner_id' => $ownerId,
    ], ['objectType' => $this->objectType]);

    $this->userWith = fn (string $seed): User => AccessContext::user($this->tenant, [], $seed);

    $this->allowViewFor = static function (string ...$userIds): void {
        $allowed = array_values($userIds);

        Gate::before(static function (?Authenticatable $user, string $ability) use ($allowed): ?bool {
            if ($ability !== 'view') {
                return null;
            }

            return in_array((string) $user?->getAuthIdentifier(), $allowed, true);
        });
    };

    $this->keysOf = static fn (Collection $users): array => $users
        ->map(static fn (User $user): string => (string) $user->getKey())
        ->all();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('drops a watcher who may no longer read the record', function (): void {
    $reader = ($this->userWith)('watcher-reader');
    $blind = ($this->userWith)('watcher-blind');

    ($this->allowViewFor)((string) $reader->getKey());

    $directory = new StaticWatcherDirectory(
        [],
        [(string) $reader->getKey(), (string) $blind->getKey()],
        [(string) $reader->getKey() => $reader, (string) $blind->getKey() => $blind],
    );

    expect(($this->keysOf)((new WatcherResolver($directory))->recipientsFor(($this->recordWith)(null))))
        ->toBe([(string) $reader->getKey()]);
});

it('adds the owner of the record to the recipients', function (): void {
    $owner = ($this->userWith)('watcher-owner');
    ($this->allowViewFor)((string) $owner->getKey());

    $directory = new StaticWatcherDirectory([], [], [(string) $owner->getKey() => $owner]);

    expect(($this->keysOf)((new WatcherResolver($directory))->recipientsFor(($this->recordWith)((string) $owner->getKey()))))
        ->toBe([(string) $owner->getKey()]);
});

it('never names the owner twice when the owner also watches the record', function (): void {
    $owner = ($this->userWith)('watcher-owner');
    ($this->allowViewFor)((string) $owner->getKey());

    $directory = new StaticWatcherDirectory(
        [],
        [(string) $owner->getKey()],
        [(string) $owner->getKey() => $owner],
    );

    expect(($this->keysOf)((new WatcherResolver($directory))->recipientsFor(($this->recordWith)((string) $owner->getKey()))))
        ->toBe([(string) $owner->getKey()]);
});

it('drops an owner who may not read the record either', function (): void {
    $owner = ($this->userWith)('watcher-owner');
    ($this->allowViewFor)();

    $directory = new StaticWatcherDirectory([], [], [(string) $owner->getKey() => $owner]);

    expect((new WatcherResolver($directory))->recipientsFor(($this->recordWith)((string) $owner->getKey())))
        ->toBeEmpty();
});

it('asks nobody at all when the record has neither a watcher nor an owner', function (): void {
    expect((new WatcherResolver(new StaticWatcherDirectory))->recipientsFor(($this->recordWith)(null)))
        ->toBeEmpty();
});

it('reads the watchers of exactly one record', function (): void {
    $record = ($this->recordWith)(null);

    $attempt = QueryShape::attemptedBy(fn () => (new WatcherDirectory)->watcherUserIdsFor($record));

    expect($attempt?->targets('record_watchers'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $record->getKey()))->toBeTrue();
});
