<?php

declare(strict_types=1);

use App\Support\Authorization\RowAccess\TeamAccessRuleSource;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->parentTeam = ModelStub::ulid('parent-team');
    $this->childTeam = ModelStub::ulid('child-team');

    $this->attempted = fn (): ?QueryShape => QueryShape::attemptedBy(
        fn (): mixed => app(TeamAccessRuleSource::class)->activeFor([$this->childTeam, $this->parentTeam]),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('reads the rules of every team in the chain', function (): void {
    $shape = ($this->attempted)();

    expect($shape)->not->toBeNull()
        ->and($shape->targets('team_record_access_rules'))->toBeTrue()
        ->and($shape->sql)->toContain('"team_id" in (?, ?)')
        ->and($shape->hasBinding($this->childTeam))->toBeTrue()
        ->and($shape->hasBinding($this->parentTeam))->toBeTrue();
});

it('never hands an inactive rule to the resolver', function (): void {
    $shape = ($this->attempted)();

    expect($shape->sql)->toContain('"is_active" = ?')
        ->and($shape->hasBinding(1))->toBeTrue();
});

it('keeps the rule lookup inside the current tenant and hides soft deleted rules', function (): void {
    $shape = ($this->attempted)();

    expect($shape->isScopedToTenant('team_record_access_rules', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('team_record_access_rules'))->toBeTrue();
});
