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

it('flushes the memoized field visibility at every octane request boundary', function (): void {
    /** @var list<string> $flush */
    $flush = (array) config('octane.flush');

    expect($flush)->toContain(FieldVisibilityResolver::class);
});
