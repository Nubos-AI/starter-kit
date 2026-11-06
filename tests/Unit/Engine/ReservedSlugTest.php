<?php

declare(strict_types=1);

use App\Exceptions\Engine\ReservedSlugException;
use App\Support\Engine\SlugGenerator;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->generator = new SlugGenerator;
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a slug that a v1 system route already owns before it ever asks the database', function (string $name): void {
    expect(config('engine.reserved_slugs'))->toContain(mb_strtolower($name))
        ->and(fn (): string => $this->generator->generate($name))
        ->toThrow(ReservedSlugException::class);
})->with(['reports', 'dashboards', 'goals']);

it('refuses a name that slugs to nothing at all', function (): void {
    expect(fn (): string => $this->generator->generate('///'))
        ->toThrow(ReservedSlugException::class);
});

it('looks a slug outside the reserved list up before minting it', function (): void {
    expect(config('engine.reserved_slugs'))->not->toContain('contract');

    $attempt = QueryShape::attemptedBy(fn (): string => $this->generator->generate('Contract'));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('object_types'))->toBeTrue()
        ->and($attempt?->hasBinding('contract'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('object_types', (string) $this->tenant->getKey()))->toBeTrue();
});
