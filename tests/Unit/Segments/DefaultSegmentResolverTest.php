<?php

declare(strict_types=1);

use App\Models\ObjectType;
use App\Support\Segments\DefaultSegmentResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('default-segment-type'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'leads',
        'slug' => 'leads',
        'name' => 'Leads',
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('asks for the oldest live default segment of the object type within the tenant', function (): void {
    $attempt = QueryShape::attemptedBy(fn (): ?string => app(DefaultSegmentResolver::class)->idFor($this->objectType));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('segments'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('segments', (string) $this->tenant->getKey()))->toBeTrue()
        ->and($attempt?->hidesSoftDeleted('segments'))->toBeTrue()
        ->and($attempt?->sql)->toContain('"object_type_id" = ?')
        ->and($attempt?->sql)->toContain('"is_default" = ?')
        ->and($attempt?->sql)->toEndWith('order by "id" asc limit 1')
        ->and($attempt?->bindings)->toBe([(string) $this->objectType->getKey(), 1, (string) $this->tenant->getKey()]);
});
