<?php

declare(strict_types=1);

use App\Models\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(bool):ObjectType */
    $this->objectType = fn (bool $isSystem): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid($isSystem ? 'system-type' : 'regular-type'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'attachments',
        'slug' => 'attachments',
        'name' => 'System',
        'is_system' => $isSystem,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to update a system object type instead of sending a statement', function (): void {
    $type = ($this->objectType)(true);

    expect(fn (): bool => $type->update(['name' => 'Hacked']))
        ->toThrow(AuthorizationException::class);
});

it('lets a regular object type through to its update statement', function (): void {
    $type = ($this->objectType)(false);

    expect(fn (): bool => $type->update(['name' => 'Companies']))
        ->toThrow(PDOException::class);
});

it('refuses to rename the slug of an object type that already carries one', function (): void {
    expect(fn (): bool => ($this->objectType)(false)->update(['slug' => 'different']))
        ->toThrow(AuthorizationException::class);
});
