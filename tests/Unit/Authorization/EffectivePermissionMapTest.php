<?php

declare(strict_types=1);

use App\Models\ObjectType;
use App\Support\Authorization\EffectivePermissionMap;
use App\Support\Authorization\PermissionCatalog;
use Illuminate\Database\Eloquent\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->catalog = app(PermissionCatalog::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('answers a verdict for every catalogued permission and denies what was never granted', function (): void {
    $tenant = AccessContext::tenant();
    $user = AccessContext::user($tenant);
    AccessContext::grant('members.view');

    $objectTypes = new Collection([
        ModelStub::make(ObjectType::class, [
            'id' => ModelStub::ulid('companies'),
            'tenant_id' => $tenant->getKey(),
            'slug' => 'companies',
        ]),
    ]);

    $map = app(EffectivePermissionMap::class)->forUser($user, $objectTypes);

    expect(array_keys($map))->toEqualCanonicalizing($this->catalog->all($objectTypes))
        ->and($map['members.view'])->toBeTrue()
        ->and($map['roles.view'])->toBeFalse()
        ->and($map)->toHaveKey('companies.view')
        ->and($map['companies.view'])->toBeFalse();
});
