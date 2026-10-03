<?php

declare(strict_types=1);

use App\Actions\Engine\UpdateObjectTypeAction;
use App\Enums\Ui\NavIcon;
use App\Models\ObjectType;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
        'name' => 'Companies',
        'is_system' => false,
    ]);

    /** @var callable(array<string, mixed>):ObjectType */
    $this->update = fn (array $input): ObjectType => app(UpdateObjectTypeAction::class)
        ->execute($this->objectType, ['name' => 'Companies', ...$input]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('starts a new object type navigable with the database icon at the front', function (): void {
    $fresh = new ObjectType;

    expect($fresh->is_navigable)->toBeTrue()
        ->and($fresh->nav_icon)->toBe(NavIcon::Database)
        ->and($fresh->nav_position)->toBe(0);
});

it('refuses an icon key that no navigation icon carries', function (): void {
    expect(fn (): ObjectType => ($this->update)(['nav_icon' => 'not-a-real-icon']))
        ->toThrow(ValidationException::class);
});

it('refuses a navigation position before the first one', function (): void {
    expect(fn (): ObjectType => ($this->update)(['nav_position' => -1]))
        ->toThrow(ValidationException::class);
});

it('refuses a navigation position beyond the column it is stored in', function (): void {
    expect(fn (): ObjectType => ($this->update)(['nav_position' => 65_536]))
        ->toThrow(ValidationException::class);
});

it('refuses a retention period that would purge a record on the day it was deleted', function (): void {
    expect(fn (): ObjectType => ($this->update)(['retention_days' => 0]))
        ->toThrow(ValidationException::class);
});

it('refuses an object type without a name at all', function (): void {
    expect(fn (): ObjectType => app(UpdateObjectTypeAction::class)->execute($this->objectType, []))
        ->toThrow(ValidationException::class);
});

it('takes a well formed set of navigation flags on to the write', function (): void {
    expect(fn (): ObjectType => ($this->update)([
        'is_navigable' => false,
        'nav_position' => 7,
        'nav_icon' => NavIcon::Users->value,
    ]))->toThrow(PDOException::class);
});
