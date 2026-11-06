<?php

declare(strict_types=1);

use App\Actions\Governance\CreateAbsenceDelegationAction;
use App\Models\AbsenceDelegation;
use App\Support\Governance\AbsenceOverlapGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->actor = AccessContext::user($this->tenant);

    $this->action = fn (): CreateAbsenceDelegationAction => new CreateAbsenceDelegationAction(new AbsenceOverlapGuard);

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    $this->input = function (array $overrides = []): array {
        return [
            'userId' => (string) $this->actor->getKey(),
            'delegateId' => ModelStub::ulid('delegate'),
            'startsAt' => '2026-04-01T00:00:00+02:00',
            'endsAt' => '2026-04-08T00:00:00+02:00',
            ...$overrides,
        ];
    };

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, list<string>>
     */
    $this->errorsOf = function (array $overrides): array {
        try {
            ($this->action)()->execute($this->actor, ($this->input)($overrides));
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted input it should have refused');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to record an absence without a bound tenant and never validates the input', function (): void {
    AccessContext::forgetTenant();

    $tenantless = ModelStub::make($this->actor::class, ['id' => ModelStub::ulid('tenantless'), 'tenant_id' => null]);

    expect(fn () => ($this->action)()->execute($tenantless, ($this->input)()))
        ->toThrow(AuthorizationException::class);
});

it('refuses an absence for a person that is not named by a ulid', function (mixed $userId): void {
    expect(($this->errorsOf)(['userId' => $userId, 'delegateId' => 'also-not-a-ulid']))
        ->toHaveKey('userId');
})->with([
    'missing' => null,
    'empty' => '',
    'plain word' => 'somebody',
    'numeric' => 42,
]);

it('refuses a period whose end is not after its start', function (string $endsAt): void {
    $errors = ($this->errorsOf)([
        'userId' => 'not-a-ulid',
        'delegateId' => 'also-not-a-ulid',
        'startsAt' => '2026-04-08T00:00:00+00:00',
        'endsAt' => $endsAt,
    ]);

    expect($errors)->toHaveKey('endsAt');
})->with([
    'identical' => '2026-04-08T00:00:00+00:00',
    'earlier' => '2026-04-07T00:00:00+00:00',
]);

it('refuses a period without any bounds at all', function (): void {
    $errors = ($this->errorsOf)([
        'userId' => 'not-a-ulid',
        'delegateId' => 'also-not-a-ulid',
        'startsAt' => null,
        'endsAt' => null,
    ]);

    expect($errors)->toHaveKeys(['startsAt', 'endsAt']);
});

it('looks the delegated person up inside the bound tenant and among the living only', function (): void {
    $shape = QueryShape::attemptedBy(fn () => ($this->action)()->execute($this->actor, ($this->input)()));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->actor->getKey()))->toBeTrue();
});

it('never looks a delegate of another tenant up outside the bound tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn () => ($this->action)()->execute($this->actor, ($this->input)([
        'userId' => 'not-a-ulid',
        'delegateId' => ModelStub::ulid('foreign-delegate'),
    ])));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('foreign-delegate')))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps the tenant, the delegated person and the delegate on the fillable list', function (): void {
    $delegation = new AbsenceDelegation;

    $delegation->fill([
        'tenant_id' => (string) $this->tenant->getKey(),
        'user_id' => (string) $this->actor->getKey(),
        'delegate_id' => ModelStub::ulid('delegate'),
        'created_by_id' => (string) $this->actor->getKey(),
        'starts_at' => '2026-04-01 00:00:00',
        'ends_at' => '2026-04-08 00:00:00',
    ]);

    expect($delegation->getAttributes())->toHaveKeys([
        'tenant_id',
        'user_id',
        'delegate_id',
        'created_by_id',
        'starts_at',
        'ends_at',
    ]);
});
