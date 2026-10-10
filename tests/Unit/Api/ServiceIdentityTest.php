<?php

declare(strict_types=1);

use App\Actions\Api\ProvisionServiceUserAction;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks the service identity up by an address derived from the tenant', function (): void {
    $shape = QueryShape::attemptedBy(fn (): User => (new ProvisionServiceUserAction)->execute($this->tenant));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('users'))->toBeTrue()
        ->and($shape->bindings)->toContain('service-'.strtolower((string) $this->tenant->getKey()).'@service.invalid');
});

it('gives every tenant its own service identity', function (): void {
    $other = AccessContext::tenant('second-tenant');

    $first = QueryShape::attemptedBy(fn (): User => (new ProvisionServiceUserAction)->execute($this->tenant));
    $second = QueryShape::attemptedBy(fn (): User => (new ProvisionServiceUserAction)->execute($other));

    expect($first->bindings)->not->toBe($second->bindings)
        ->and($second->bindings)->toContain('service-'.strtolower((string) $other->getKey()).'@service.invalid');
});

it('flushes the memoized field visibility at every octane request boundary', function (): void {
    /** @var list<string> $flush */
    $flush = (array) config('octane.flush');

    expect($flush)->toContain(FieldVisibilityResolver::class);
});
