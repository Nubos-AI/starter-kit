<?php

declare(strict_types=1);

use App\Actions\Preferences\UpdateUserPreferencesAction;
use App\Models\User;
use App\Support\Preferences\PreferencePolicyResolver;
use App\Support\Preferences\PreferenceSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::user($this->tenant);
    $this->objectTypeId = (string) Str::ulid();

    /** @var callable(array<string, mixed>):UpdateUserPreferencesAction */
    $this->actionUnder = static function (array $storedPolicy): UpdateUserPreferencesAction {
        $policies = new class($storedPolicy) extends PreferencePolicyResolver
        {
            /**
             * @param  array<string, mixed>  $storedPolicy
             */
            public function __construct(private readonly array $storedPolicy) {}

            /**
             * @return array<string, array<string, bool>>
             */
            public function forUser(User $user): array
            {
                return $this->withDefaults($this->storedPolicy);
            }
        };

        return new UpdateUserPreferencesAction(new PreferenceSchema, $policies);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses an invalid payload before it ever opens a transaction', function (array $payload): void {
    $action = ($this->actionUnder)([]);

    expect(fn (): bool => WriteAttempt::reachedTheDatabase(fn (): mixed => $action->execute($this->user, $payload)))
        ->toThrow(ValidationException::class);
})->with([
    'an unknown scope' => [['dashboards' => ['foo' => 'bar']]],
    'an unknown view mode' => [['objectTypes' => ['01JBRT0000000000000000000A' => ['viewMode' => 'gallery']]]],
]);

it('never reaches the database when every key the payload carries is switched off', function (): void {
    $action = ($this->actionUnder)(['columnsAndSorting' => ['records' => false, 'configuration' => true]]);

    $reached = WriteAttempt::reachedTheDatabase(fn (): mixed => $action->execute($this->user, [
        'objectTypes' => [$this->objectTypeId => ['columnState' => [['colId' => 'name']]]],
    ]));

    expect($reached)->toBeFalse();
});

it('never reaches the database for a last used segment while the filter category is off', function (): void {
    $action = ($this->actionUnder)([]);

    $reached = WriteAttempt::reachedTheDatabase(fn (): mixed => $action->execute($this->user, [
        'objectTypes' => [$this->objectTypeId => ['lastSegmentId' => (string) Str::ulid()]],
    ]));

    expect($reached)->toBeFalse();
});

it('carries an allowed value all the way to the database', function (): void {
    $action = ($this->actionUnder)([]);

    $reached = WriteAttempt::reachedTheDatabase(fn (): mixed => $action->execute($this->user, [
        'objectTypes' => [$this->objectTypeId => ['viewMode' => 'kanban']],
    ]));

    expect($reached)->toBeTrue();
});

it('carries a last used segment to the database once the filter category is switched on', function (): void {
    $action = ($this->actionUnder)(['filterAndSegment' => ['records' => true]]);

    $reached = WriteAttempt::reachedTheDatabase(fn (): mixed => $action->execute($this->user, [
        'objectTypes' => [$this->objectTypeId => ['lastSegmentId' => (string) Str::ulid()]],
    ]));

    expect($reached)->toBeTrue();
});

it('stops before the database for a user without a tenant even when the payload is allowed', function (): void {
    $action = ($this->actionUnder)([]);
    $tenantless = AccessContext::user($this->tenant, ['tenant_id' => null], 'tenantless');

    $reached = WriteAttempt::reachedTheDatabase(fn (): mixed => $action->execute($tenantless, [
        'settings' => ['density' => 'comfortable'],
    ]));

    expect($reached)->toBeFalse();
});

it('overwrites only the keys the patch names and keeps every neighbouring slice the user stored', function (): void {
    $otherObjectTypeId = (string) Str::ulid();
    $stored = [
        'settings' => ['density' => 'compact', 'sidebarOpen' => false],
        'objectTypes' => [
            $this->objectTypeId => ['viewMode' => 'list', 'kanbanAxis' => 'stage'],
            $otherObjectTypeId => ['viewMode' => 'kanban'],
        ],
    ];

    $connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "user_preferences"') ? [[
            'id' => (string) Str::ulid(),
            'tenant_id' => (string) $this->tenant->getKey(),
            'user_id' => (string) $this->user->getKey(),
            'settings' => json_encode($stored),
        ]] : [],
        static fn (): int => 1,
    );

    try {
        ($this->actionUnder)([])->execute($this->user, [
            'objectTypes' => [$this->objectTypeId => ['viewMode' => 'kanban']],
        ]);
    } finally {
        StaticQueryConnection::uninstall();
    }

    $update = collect($connection->writtenStatements)
        ->first(static fn (array $statement): bool => str_starts_with($statement['sql'], 'update "user_preferences"'));

    expect($update)->not->toBeNull();

    $written = collect($update['bindings'])
        ->filter(static fn (mixed $binding): bool => is_string($binding) && str_starts_with($binding, '{'))
        ->map(static fn (string $binding): mixed => json_decode($binding, true))
        ->first();

    expect($written)->toBe([
        'settings' => ['density' => 'compact', 'sidebarOpen' => false],
        'objectTypes' => [
            $this->objectTypeId => ['viewMode' => 'kanban', 'kanbanAxis' => 'stage'],
            $otherObjectTypeId => ['viewMode' => 'kanban'],
        ],
    ]);
});
