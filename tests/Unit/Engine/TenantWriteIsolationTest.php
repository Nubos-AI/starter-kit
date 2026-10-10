<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Models\CustomRecord;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->foreignTenantId = ModelStub::ulid('foreign-tenant');
    $this->objectTypeId = ModelStub::ulid('companies');

    /** @var callable(array<string, mixed>):?QueryShape */
    $this->attempt = fn (array $payload): ?QueryShape => QueryShape::attemptedBy(
        fn (): CustomRecord => app(CreateRecordAction::class)->execute([
            'object_type_id' => $this->objectTypeId,
            'data' => ['amount' => 1],
            ...$payload,
        ]),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks the object type of a write up inside the bound tenant only', function (): void {
    $attempt = ($this->attempt)([]);

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('object_types'))->toBeTrue()
        ->and($attempt?->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('never reaches for the tenant a request asked for when a tenant is bound', function (): void {
    $attempt = ($this->attempt)(['tenant_id' => $this->foreignTenantId]);

    expect($attempt)->not->toBeNull()
        ->and($attempt?->hasBinding($this->foreignTenantId))->toBeFalse()
        ->and($attempt?->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('keeps the object type lookup tenant bound even when the caller supplies an owner of its own', function (): void {
    $attempt = ($this->attempt)(['owner_id' => ModelStub::ulid('owner'), 'tenant_id' => $this->foreignTenantId]);

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('object_types'))->toBeTrue()
        ->and($attempt?->hasBinding($this->foreignTenantId))->toBeFalse();
});
