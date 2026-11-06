<?php

declare(strict_types=1);

use App\Actions\Engine\UpdateObjectTypeAction;
use App\Enums\Ui\NavIcon;
use App\Models\ObjectType;
use Illuminate\Support\Facades\DB;
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

it('refuses navigation and retention flags outside their allowed range on the field the form renders', function (array $input, string $errorKey): void {
    expect(fn (): ObjectType => ($this->update)($input))
        ->toThrow(function (ValidationException $exception) use ($errorKey): void {
            expect($exception->errors())->toHaveKey($errorKey);
        });
})->with([
    'an icon key that no navigation icon carries' => [['nav_icon' => 'not-a-real-icon'], 'nav_icon'],
    'a navigation position before the first one' => [['nav_position' => -1], 'nav_position'],
    'a navigation position beyond the column it is stored in' => [['nav_position' => 65_536], 'nav_position'],
    'a retention period that would purge a record on the day it was deleted' => [['retention_days' => 0], 'retention_days'],
]);

it('refuses an object type without a name at all', function (): void {
    expect(fn (): ObjectType => app(UpdateObjectTypeAction::class)->execute($this->objectType, []))
        ->toThrow(ValidationException::class);
});

it('takes a well formed set of navigation flags on to the write', function (): void {
    DB::shouldReceive('transaction')->andReturnUsing(static fn (Closure $callback): mixed => $callback());
    $written = null;

    ObjectType::updating(static function (ObjectType $objectType) use (&$written): bool {
        $written = $objectType->getDirty();

        return false;
    });

    ($this->update)([
        'is_navigable' => false,
        'nav_position' => 7,
        'nav_icon' => NavIcon::Users->value,
    ]);

    expect($written)->toMatchArray([
        'is_navigable' => false,
        'nav_position' => 7,
        'nav_icon' => NavIcon::Users->value,
    ]);
});
