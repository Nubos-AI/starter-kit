<?php

declare(strict_types=1);

use App\Models\ObjectType;
use App\Models\User;
use App\Policies\Engine\ObjectTypePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->policy = new ObjectTypePolicy;

    /** @var callable(bool):ObjectType */
    $this->objectType = fn (bool $isSystem): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid($isSystem ? 'system-type' : 'generic-type'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'attachments',
        'is_system' => $isSystem,
    ]);

    /** @var callable(string ...):User */
    $this->manager = function (string ...$abilities): User {
        AccessContext::grant(...$abilities);

        return AccessContext::user($this->tenant);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('denies updating a system object type to a holder of the update permission', function (): void {
    $manager = ($this->manager)('object-types.update', 'object-types.delete');

    expect($this->policy->update($manager, ($this->objectType)(true)))->toBeFalse()
        ->and($this->policy->delete($manager, ($this->objectType)(true)))->toBeFalse();
});

it('allows the same holder to manage a regular object type', function (): void {
    $manager = ($this->manager)('object-types.update', 'object-types.delete');

    expect($this->policy->update($manager, ($this->objectType)(false)))->toBeTrue()
        ->and($this->policy->delete($manager, ($this->objectType)(false)))->toBeTrue();
});

it('denies a viewer without the write permissions even on a regular object type', function (): void {
    $viewer = ($this->manager)('object-types.view');

    expect($this->policy->view($viewer, ($this->objectType)(false)))->toBeTrue()
        ->and($this->policy->update($viewer, ($this->objectType)(false)))->toBeFalse()
        ->and($this->policy->delete($viewer, ($this->objectType)(false)))->toBeFalse();
});

it('stops a system object type at the model even when no policy was asked', function (): void {
    expect(fn (): bool => ($this->objectType)(true)->update(['name' => 'tampered']))
        ->toThrow(AuthorizationException::class);
});

it('lets a regular object type reach the database instead', function (): void {
    expect(fn (): bool => ($this->objectType)(false)->update(['name' => 'Companies']))
        ->toThrow(PDOException::class);
});
