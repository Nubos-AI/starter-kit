<?php

declare(strict_types=1);

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Watchers\WatcherDirectory;
use App\Support\Watchers\WatcherEligibility;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticWatcherDirectory;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant('watcher-tenant');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('watcher-object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('watched-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
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

    $this->eligibilityWith = static fn (WatcherDirectory $directory): WatcherEligibility => new WatcherEligibility($directory);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks nothing at all when no user is named', function (): void {
    $directory = new StaticWatcherDirectory;

    ($this->eligibilityWith)($directory)->assertMayWatch($this->record, [], 'user_ids', 'refused');

    expect($directory->askedForCandidates)->toBe([]);
});

it('accepts a user of the tenant who may view the record', function (): void {
    $watcher = ($this->userWith)('watcher-eligible');
    ($this->allowViewFor)((string) $watcher->getKey());

    $directory = new StaticWatcherDirectory([(string) $watcher->getKey() => $watcher]);

    ($this->eligibilityWith)($directory)->assertMayWatch($this->record, [(string) $watcher->getKey()], 'user_ids', 'refused');

    expect($directory->askedForCandidates)->toBe([(string) $watcher->getKey()]);
});

it('refuses a user who may not view the record', function (): void {
    $blind = ($this->userWith)('watcher-blind');
    ($this->allowViewFor)(ModelStub::ulid('someone-else'));

    $directory = new StaticWatcherDirectory([(string) $blind->getKey() => $blind]);

    expect(fn () => ($this->eligibilityWith)($directory)
        ->assertMayWatch($this->record, [(string) $blind->getKey()], 'user_ids', 'refused'))
        ->toThrow(ValidationException::class);
});

it('refuses a user the directory does not hand back at all', function (): void {
    $stranger = ModelStub::ulid('watcher-stranger');
    ($this->allowViewFor)($stranger);

    expect(fn () => ($this->eligibilityWith)(new StaticWatcherDirectory)
        ->assertMayWatch($this->record, [$stranger], 'user_ids', 'refused'))
        ->toThrow(ValidationException::class);
});

it('refuses the whole batch as soon as one named user may not view the record', function (): void {
    $eligible = ($this->userWith)('watcher-eligible');
    $blind = ($this->userWith)('watcher-blind');

    ($this->allowViewFor)((string) $eligible->getKey());

    $directory = new StaticWatcherDirectory([
        (string) $eligible->getKey() => $eligible,
        (string) $blind->getKey() => $blind,
    ]);

    expect(fn () => ($this->eligibilityWith)($directory)->assertMayWatch(
        $this->record,
        [(string) $eligible->getKey(), (string) $blind->getKey()],
        'user_ids',
        'refused',
    ))->toThrow(ValidationException::class);
});

it('names the field it was given in the refusal so the form can show it', function (): void {
    $blind = ($this->userWith)('watcher-blind');
    ($this->allowViewFor)();

    $directory = new StaticWatcherDirectory([(string) $blind->getKey() => $blind]);

    try {
        ($this->eligibilityWith)($directory)->assertMayWatch($this->record, [(string) $blind->getKey()], 'owner_ids', 'Nur Benutzer mit Leserecht.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['owner_ids' => ['Nur Benutzer mit Leserecht.']]);

        return;
    }

    $this->fail('an ineligible watcher was accepted');
});

it('looks the candidates up inside the tenant of the record and never among service identities', function (): void {
    $candidateId = ModelStub::ulid('watcher-candidate');

    $attempt = QueryShape::attemptedBy(fn () => (new WatcherDirectory)->candidatesFor($this->record, [$candidateId]));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('users'))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hasBinding($candidateId))->toBeTrue()
        ->and($attempt?->sql)->toContain('"is_service" = ?');
});
