<?php

declare(strict_types=1);

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Governance\CandidateSource;
use App\Enums\Users\UserStatus;
use App\Support\Governance\CandidateCircleResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->resolver = fn (): CandidateCircleResolver => app(CandidateCircleResolver::class);

    /**
     * @param  array<string, mixed>  $config
     */
    $this->circle = static fn (array $config): CandidateCircle => CandidateCircle::fromArray($config);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('pins the team lookup to the named tenant although it drops every global scope', function (): void {
    $shape = QueryShape::attemptedBy(fn () => ($this->resolver)()->countWithoutRecord(
        ($this->circle)([
            'sources' => [CandidateSource::Team->value],
            'team_ids' => [ModelStub::ulid('team')],
        ]),
        (string) $this->tenant->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('teams'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('team')))->toBeTrue();
});

it('never reads a team of another tenant even when the caller names its identifier', function (): void {
    $shape = QueryShape::attemptedBy(fn () => ($this->resolver)()->countWithoutRecord(
        ($this->circle)([
            'sources' => [CandidateSource::Team->value],
            'team_ids' => [ModelStub::ulid('foreign-team')],
        ]),
        (string) $this->tenant->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding(ModelStub::ulid('foreign-team')))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('pins the role assignment lookup to the named tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn () => ($this->resolver)()->countWithoutRecord(
        ($this->circle)([
            'sources' => [CandidateSource::Role->value],
            'role_ids' => [ModelStub::ulid('role')],
        ]),
        (string) $this->tenant->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('role')))->toBeTrue();
});

it('admits only accepted human accounts of the named tenant as candidates', function (): void {
    $shape = QueryShape::attemptedBy(fn () => ($this->resolver)()->countWithoutRecord(
        ($this->circle)([
            'sources' => [CandidateSource::FixedList->value],
            'user_ids' => [ModelStub::ulid('candidate')],
        ]),
        (string) $this->tenant->getKey(),
    ));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->sql)->toContain('"is_service" = ?')
        ->and($shape->sql)->toContain('"status" = ?')
        ->and($shape->hasBinding(UserStatus::Accepted->value))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding(ModelStub::ulid('candidate')))->toBeTrue();
});

it('counts nothing and reads nothing for a circle that depends on a record', function (): void {
    $counted = null;

    $shape = QueryShape::attemptedBy(function () use (&$counted): void {
        $counted = ($this->resolver)()->countWithoutRecord(
            ($this->circle)([
                'sources' => [CandidateSource::Team->value],
                'include_record_team' => true,
            ]),
            (string) $this->tenant->getKey(),
        );
    });

    expect($shape)->toBeNull()
        ->and($counted)->toBeNull();
});

it('counts nothing and reads nothing for an empty circle', function (): void {
    $counted = null;

    $shape = QueryShape::attemptedBy(function () use (&$counted): void {
        $counted = ($this->resolver)()->countWithoutRecord(($this->circle)([]), (string) $this->tenant->getKey());
    });

    expect($shape)->toBeNull()
        ->and($counted)->toBe(0);
});
