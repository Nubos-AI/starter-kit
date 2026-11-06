<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Reports\ReportOptions;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable():User */
    $this->viewer = fn (): User => AccessContext::actAs(AccessContext::user($this->tenant));
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('offers only reports of the tenant of the user, ordered by name, with the object type loaded', function (): void {
    GateSpy::allowing('view', 'create');

    $user = ($this->viewer)();

    $shape = QueryShape::attemptedBy(fn (): array => (new ReportOptions)->forUser($user));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('reports'))->toBeTrue()
        ->and($shape->isScopedToTenant('reports', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('order by "name" asc')
        ->and($shape->hidesSoftDeleted('reports'))->toBeTrue();
});
