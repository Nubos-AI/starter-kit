<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('dedup-object-type');
    $this->foreignTenantId = ModelStub::ulid('foreign-tenant');

    /** @var callable(list<string>):void */
    $this->registryKnowing = fn (array $fieldKeys): mixed => app()->instance(
        ObjectTypeRegistry::class,
        new class($this->objectTypeId, $fieldKeys) extends ObjectTypeRegistry
        {
            /**
             * @param  list<string>  $fieldKeys
             */
            public function __construct(private readonly string $objectTypeId, private readonly array $fieldKeys) {}

            /**
             * @return Collection<string, FieldDefinition>
             */
            public function fields(string $objectTypeId): Collection
            {
                if ($objectTypeId !== $this->objectTypeId) {
                    return new Collection;
                }

                return (new Collection($this->fieldKeys))
                    ->mapWithKeys(static fn (string $key): array => [$key => ModelStub::make(FieldDefinition::class, [
                        'key' => $key,
                        'field_type' => FieldType::TextShort->value,
                    ])]);
            }
        },
    );

    /** @var callable(array<int, mixed>|null):ObjectType */
    $this->typeWithDedupKeys = fn (?array $groups): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenant->getKey(),
        'dedup_keys' => $groups,
    ]);

    /** @var callable(ObjectType, array<string, mixed>, string):?QueryShape */
    $this->lookupOf = static fn (ObjectType $type, array $values, string $tenantId): ?QueryShape => QueryShape::attemptedBy(
        static fn (): mixed => $type->findDuplicates($values, $tenantId),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks for a duplicate over a single configured field inside the given tenant only', function (): void {
    ($this->registryKnowing)(['email']);

    $shape = ($this->lookupOf)(
        ($this->typeWithDedupKeys)([['email']]),
        ['email' => 'a@example.com'],
        (string) $this->tenant->getKey(),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue()
        ->and($shape->sql)->toContain('"custom_records"."object_type_id" = ?')
        ->and($shape->hasBinding($this->objectTypeId))->toBeTrue()
        ->and($shape->sql)->toContain('"deleted_at" is null')
        ->and($shape->sql)->toContain('(data->>\'email\')')
        ->and($shape->hasBinding('a@example.com'))->toBeTrue();
});

it('requires every field of a composite group to match at once', function (): void {
    ($this->registryKnowing)(['first_name', 'last_name']);

    $shape = ($this->lookupOf)(
        ($this->typeWithDedupKeys)([['first_name', 'last_name']]),
        ['first_name' => 'Max', 'last_name' => 'Mustermann'],
        (string) $this->tenant->getKey(),
    );

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('(data->>\'first_name\')')
        ->and($shape->sql)->toContain('(data->>\'last_name\')')
        ->and($shape->hasBinding('Max'))->toBeTrue()
        ->and($shape->hasBinding('Mustermann'))->toBeTrue();
});

it('binds the lookup to the tenant it was asked about, never to the one bound in the container', function (): void {
    ($this->registryKnowing)(['email']);

    $shape = ($this->lookupOf)(
        ($this->typeWithDedupKeys)([['email']]),
        ['email' => 'shared@example.com'],
        $this->foreignTenantId,
    );

    expect($shape)->not->toBeNull()
        ->and($shape->hasBinding($this->foreignTenantId))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('never queries a dedup key that is not a defined field of this object type', function (): void {
    ($this->registryKnowing)(['email']);

    $type = ($this->typeWithDedupKeys)([['email->x'], ['a"; --'], [42, ['nested']], ['Email']]);

    expect(($this->lookupOf)($type, [
        'email->x' => 'a@example.com',
        'a"; --' => 'a@example.com',
        'Email' => 'a@example.com',
        42 => 'a@example.com',
    ], (string) $this->tenant->getKey()))->toBeNull();
});

it('never queries at all without configured dedup keys', function (): void {
    ($this->registryKnowing)(['email']);

    expect(($this->lookupOf)(($this->typeWithDedupKeys)(null), ['email' => 'a@example.com'], (string) $this->tenant->getKey()))->toBeNull()
        ->and(($this->lookupOf)(($this->typeWithDedupKeys)([]), ['email' => 'a@example.com'], (string) $this->tenant->getKey()))->toBeNull();
});

it('skips a group whose values the candidate does not carry in full', function (): void {
    ($this->registryKnowing)(['first_name', 'last_name']);

    $type = ($this->typeWithDedupKeys)([['first_name', 'last_name']]);

    expect(($this->lookupOf)($type, ['first_name' => 'Max', 'last_name' => null], (string) $this->tenant->getKey()))->toBeNull()
        ->and(($this->lookupOf)($type, ['first_name' => 'Max'], (string) $this->tenant->getKey()))->toBeNull();
});
