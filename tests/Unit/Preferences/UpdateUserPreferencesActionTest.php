<?php

declare(strict_types=1);

use App\Actions\Preferences\UpdateUserPreferencesAction;
use App\Models\User;
use App\Support\Preferences\PreferencePolicyResolver;
use App\Support\Preferences\PreferenceSchema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
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

it('refuses an unknown scope before it ever opens a transaction', function (): void {
    $action = ($this->actionUnder)([]);

    expect(fn (): mixed => $action->execute($this->user, ['dashboards' => ['foo' => 'bar']]))
        ->toThrow(ValidationException::class);
});

it('refuses an unknown view mode before it ever opens a transaction', function (): void {
    $action = ($this->actionUnder)([]);

    expect(fn (): mixed => $action->execute($this->user, [
        'objectTypes' => [$this->objectTypeId => ['viewMode' => 'gallery']],
    ]))->toThrow(ValidationException::class);
});

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
