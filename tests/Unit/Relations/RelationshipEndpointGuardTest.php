<?php

declare(strict_types=1);

use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\StorageStrategy;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeCapabilityGuard;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RelationshipEndpointGuard;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(string, StorageStrategy):ObjectType */
    $this->objectType = fn (string $slug, StorageStrategy $strategy): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid($slug),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => $slug,
        'name' => ucfirst($slug),
        'storage_strategy' => $strategy->value,
    ]);

    $this->generic = ($this->objectType)('companies', StorageStrategy::Generic);
    $this->native = ($this->objectType)('users', StorageStrategy::Native);

    $this->registry = Mockery::mock(ObjectTypeRegistry::class);
    $this->capabilities = Mockery::mock(ObjectTypeCapabilityGuard::class);
    $this->guard = new RelationshipEndpointGuard($this->registry, $this->capabilities);

    /** @var callable(ObjectType, ObjectType, CascadeBehavior):array<string, mixed> */
    $this->input = fn (ObjectType $from, ObjectType $to, CascadeBehavior $cascade): array => [
        'from_object_type_id' => (string) $from->getKey(),
        'to_object_type_id' => (string) $to->getKey(),
        'cascade_behavior' => $cascade->value,
    ];

    /** @var callable(callable):array<string, list<string>> */
    $this->errorsOf = function (callable $call): array {
        try {
            $call();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };

    $this->answering = function (ObjectType ...$types): void {
        foreach ($types as $type) {
            $this->registry->shouldReceive('byId')->with((string) $type->getKey())->andReturn($type);
        }
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses an endpoint whose object type does not support relationships and names the field', function (): void {
    ($this->answering)($this->generic, $this->native);
    $this->capabilities->shouldReceive('supports')->with($this->generic, ObjectTypeCapability::Relations)->andReturnTrue();
    $this->capabilities->shouldReceive('supports')->with($this->native, ObjectTypeCapability::Relations)->andReturnFalse();

    try {
        $this->guard->assertUsable(($this->input)($this->generic, $this->native, CascadeBehavior::Restrict));
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['to_object_type_id']);
    }
});

it('checks the source endpoint before the target one', function (): void {
    ($this->answering)($this->generic, $this->native);
    $this->capabilities->shouldReceive('supports')->with($this->native, ObjectTypeCapability::Relations)->andReturnFalse();
    $this->capabilities->shouldNotReceive('supports')->with($this->generic, ObjectTypeCapability::Relations);

    try {
        $this->guard->assertUsable(($this->input)($this->native, $this->generic, CascadeBehavior::Restrict));
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['from_object_type_id']);
    }
});

it('refuses a cascading deletion as soon as one endpoint is backed natively', function (): void {
    ($this->answering)($this->generic, $this->native);
    $this->capabilities->shouldReceive('supports')->andReturnTrue();

    try {
        $this->guard->assertUsable(($this->input)($this->generic, $this->native, CascadeBehavior::Cascade));
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['cascade_behavior']);
    }
});

it('leaves a restricting or nullifying relationship onto a native object type alone', function (): void {
    ($this->answering)($this->generic, $this->native);
    $this->capabilities->shouldReceive('supports')->andReturnTrue();

    $restricting = ($this->errorsOf)(fn () => $this->guard->assertUsable(($this->input)($this->generic, $this->native, CascadeBehavior::Restrict)));
    $nullifying = ($this->errorsOf)(fn () => $this->guard->assertUsable(($this->input)($this->generic, $this->native, CascadeBehavior::Nullify)));

    expect($restricting)->toBe([])->and($nullifying)->toBe([]);
});

it('lets a cascading deletion between two generic object types pass', function (): void {
    $other = ($this->objectType)('contacts', StorageStrategy::Generic);
    ($this->answering)($this->generic, $other);
    $this->capabilities->shouldReceive('supports')->andReturnTrue();

    $errors = ($this->errorsOf)(fn () => $this->guard->assertUsable(($this->input)($this->generic, $other, CascadeBehavior::Cascade)));

    expect($errors)->toBe([]);
});
