<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Support\Collection;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('companies');
    $this->teamId = ModelStub::ulid('team');
    $this->ownerId = ModelStub::ulid('owner');

    /** @var callable(string, array<string, mixed>):FieldDefinition */
    $this->field = fn (string $key, array $flags = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => FieldType::TextShort,
        'is_searchable' => true,
        'is_encrypted' => false,
        'is_translatable' => false,
        ...$flags,
    ]);

    /** @var callable(list<FieldDefinition>):void */
    $this->registryWith = static function (array $fields): void {
        $canned = (new Collection($fields))->keyBy('key');

        app()->instance(ObjectTypeRegistry::class, new class($canned) extends ObjectTypeRegistry
        {
            /**
             * @param  Collection<string, FieldDefinition>  $canned
             */
            public function __construct(private readonly Collection $canned) {}

            /**
             * @return Collection<string, FieldDefinition>
             */
            public function fields(string $objectTypeId): Collection
            {
                return $this->canned;
            }
        });
    };

    /** @var callable(array<string, mixed>):CustomRecord */
    $this->record = fn (array $data): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'team_id' => $this->teamId,
        'owner_id' => $this->ownerId,
        'object_type_id' => $this->objectTypeId,
        'data' => $data,
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(ObjectTypeRegistry::class);
});

it('projects only the fields marked searchable into the index', function (): void {
    ($this->registryWith)([
        ($this->field)('name'),
        ($this->field)('internal', ['is_searchable' => false]),
    ]);

    $projection = ($this->record)(['name' => 'Meier GmbH', 'internal' => 'nicht suchbar'])->toSearchableArray();

    expect($projection)->toHaveKey('name')
        ->and($projection)->not->toHaveKey('internal');
});

it('never lets the plaintext of an encrypted field reach the index', function (): void {
    ($this->registryWith)([
        ($this->field)('name'),
        ($this->field)('secret', ['is_encrypted' => true]),
    ]);

    $projection = ($this->record)(['name' => 'Meier GmbH', 'secret' => 'plaintext'])->toSearchableArray();

    expect($projection)->not->toHaveKey('secret')
        ->and(json_encode($projection))->not->toContain('plaintext');
});

it('carries exactly the spine attributes the visibility filter needs and no synthetic column', function (): void {
    ($this->registryWith)([($this->field)('name')]);

    $projection = ($this->record)(['name' => 'Meier GmbH'])->toSearchableArray();

    expect(array_keys($projection))->toBe(['name', 'tenant_id', 'team_id', 'owner_id', 'object_type_id'])
        ->and($projection['tenant_id'])->toBe((string) $this->tenant->getKey())
        ->and($projection['object_type_id'])->toBe($this->objectTypeId);
});

it('projects a missing value as null instead of dropping the attribute', function (): void {
    ($this->registryWith)([($this->field)('name'), ($this->field)('city')]);

    $projection = ($this->record)(['name' => 'Meier GmbH'])->toSearchableArray();

    expect($projection)->toHaveKey('city')
        ->and($projection['city'])->toBeNull();
});

it('keeps a trashed record out of the index', function (): void {
    ($this->registryWith)([($this->field)('name')]);

    $live = ($this->record)(['name' => 'Meier GmbH']);
    $trashed = ($this->record)(['name' => 'Meier GmbH']);
    $trashed->deleted_at = now();

    expect($live->shouldBeSearchable())->toBeTrue()
        ->and($trashed->shouldBeSearchable())->toBeFalse();
});
