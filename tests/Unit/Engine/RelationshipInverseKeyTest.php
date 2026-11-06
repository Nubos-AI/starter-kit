<?php

declare(strict_types=1);

use App\Models\RelationshipType;
use App\Support\Engine\RelationshipKeyGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->generator = new RelationshipKeyGenerator;

    /** @var callable(string, list<string>):?QueryShape */
    $this->attempt = fn (string $name, array $reserved = []): ?QueryShape => QueryShape::attemptedBy(
        fn (): string => $this->generator->generate($name, $reserved),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('derives a usable key from a relationship or inverse name', function (string $name, string $expectedKey): void {
    expect(($this->attempt)($name)?->hasBinding($expectedKey))->toBeTrue();
})->with([
    'name of the relationship' => ['Employs staff', 'employs-staff'],
    'inverse name' => ['Works for', 'works-for'],
    'name that slugs to nothing' => ['///', 'relationship'],
]);

it('checks a candidate key against both key columns of every relationship type', function (): void {
    $attempt = ($this->attempt)('Employs staff');

    expect($attempt?->targets('relationship_types'))->toBeTrue()
        ->and($attempt?->sql)->toContain('"key" = ?')
        ->and($attempt?->sql)->toContain('"inverse_key" = ?')
        ->and($attempt?->hidesSoftDeleted('relationship_types'))->toBeFalse()
        ->and($attempt?->bindings)->toBe(['employs-staff', 'employs-staff', (string) $this->tenant->getKey()]);
});

it('steps past a key its own sibling already claimed in the same write', function (): void {
    $attempt = ($this->attempt)('Shared label', ['shared-label']);

    expect($attempt?->hasBinding('shared-label'))->toBeFalse()
        ->and($attempt?->hasBinding('shared-label-2'))->toBeTrue();
});

it('refuses to change a key or an inverse key once the relationship type carries one', function (string $attribute): void {
    $type = ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('relationship'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'employs-staff',
        'inverse_key' => 'works-for',
    ]);

    expect(fn (): bool => $type->update([$attribute => 'something-else']))
        ->toThrow(AuthorizationException::class);
})->with([
    'key' => ['key'],
    'inverse key' => ['inverse_key'],
]);

it('lets every other attribute of a relationship type through to the database', function (): void {
    $type = ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('relationship'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'employs-staff',
        'inverse_key' => 'works-for',
        'name' => 'Employs staff',
    ]);

    expect(fn (): bool => $type->update(['name' => 'Opportunities']))
        ->toThrow(PDOException::class);
});
