<?php

declare(strict_types=1);

use App\DTOs\Import\ImportRecordMatch;
use App\DTOs\Import\ImportRowValues;
use App\Enums\CustomFields\FieldType;
use App\Enums\Import\ImportDuplicateMode;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordExchangeIdentity;
use App\Support\Engine\RecordValidator;
use App\Support\Import\ImportRecordLocator;
use App\Support\Import\ImportRowClassifier;
use App\Support\Import\ImportRowMapper;
use App\Support\Import\MissingOptionResolver;
use Illuminate\Support\Facades\Auth;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
    ]);

    $this->targetType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('contacts'),
        'tenant_id' => $this->tenantId,
        'slug' => 'contacts',
    ]);

    $this->relationField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('contacts-field'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => 'contacts',
        'field_type' => FieldType::RelationHasMany,
        'config' => ['relationship_type_id' => ModelStub::ulid('rel')],
    ]);

    $this->existing = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('existing'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
    ]);

    $this->validator = Mockery::mock(RecordValidator::class);
    $this->validator->shouldReceive('validate')->andReturnNull()->byDefault();

    $this->mapper = Mockery::mock(ImportRowMapper::class);
    $this->locator = Mockery::mock(ImportRecordLocator::class);
    $this->exchangeIdentity = Mockery::mock(RecordExchangeIdentity::class);
    $this->registry = Mockery::mock(ObjectTypeRegistry::class);

    $this->fieldLookup = StaticObjectTypeFieldLookup::carrying(
        (string) $this->objectType->getKey(),
        [$this->relationField],
    );

    /** @var callable(array<string, mixed>, list<array{values: list<string>, field: FieldDefinition}>):void */
    $this->rowYields = function (array $data, array $relations = []): void {
        $this->mapper->shouldReceive('map')->andReturn(new ImportRowValues('EXT-1', null, $data, $relations));
    };

    /** @var callable():ImportRowClassifier */
    $this->classifier = fn (): ImportRowClassifier => new ImportRowClassifier(
        $this->validator,
        $this->mapper,
        $this->locator,
        new MissingOptionResolver,
        $this->exchangeIdentity,
        $this->registry,
        $this->fieldLookup,
    );

    /** @var callable(list<string>, ImportDuplicateMode):array{status: string, row: int, message: string|null, identity: string|null} */
    $this->classifyIn = function (array $abilities, ImportDuplicateMode $mode): array {
        $user = AccessContext::user($this->tenant, [], 'importer');
        AccessContext::actAs($user);
        AccessContext::grant(...$abilities);

        return ($this->classifier)()->classify(
            ['name' => 'Acme'],
            $this->objectType,
            ['columns' => ['name' => 'name']],
            $mode,
            $this->tenantId,
            7,
        );
    };

    /** @var callable(list<string>):array{status: string, row: int, message: string|null, identity: string|null} */
    $this->classify = fn (array $abilities): array => ($this->classifyIn)($abilities, ImportDuplicateMode::Upsert);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Auth::forgetUser();
    Mockery::close();
});

it('refuses a row when no user is signed in', function (): void {
    new FakeFieldVisibilityResolver;
    ($this->rowYields)(['name' => 'Acme']);
    $this->locator->shouldNotReceive('locate');

    $outcome = ($this->classifier)()->classify(
        ['name' => 'Acme'],
        $this->objectType,
        [],
        ImportDuplicateMode::Upsert,
        $this->tenantId,
        7,
    );

    expect($outcome['status'])->toBe('error')
        ->and($outcome['row'])->toBe(7);
});

it('demands the create or update permission of the object type for the row it classifies', function (bool $isMatched, array $abilities, string $status, ?string $messageKey): void {
    new FakeFieldVisibilityResolver;
    ($this->rowYields)(['name' => 'Acme']);
    $this->locator->shouldReceive('locate')->andReturn($isMatched ? new ImportRecordMatch($this->existing, true, null, true) : null);

    $outcome = ($this->classify)($abilities);

    expect($outcome['status'])->toBe($status)
        ->and($outcome['message'])->toBe($messageKey === null ? null : __($messageKey));
})->with([
    'new row without the create permission' => [false, ['companies.import', 'companies.update'], 'error', 'i18n.backend.support.import.import_row_classifier.you_may_not_create_records_of_this_type'],
    'new row with the create permission' => [false, ['companies.import', 'companies.create'], 'insert', null],
    'matched record without the update permission' => [true, ['companies.import', 'companies.create'], 'error', 'i18n.backend.support.import.import_row_classifier.you_may_not_update_records_of_this_type'],
    'matched record with the update permission' => [true, ['companies.import', 'companies.update'], 'update', null],
]);

it('answers not found for a matched record the caller may not see instead of revealing it', function (): void {
    new FakeFieldVisibilityResolver;
    ($this->rowYields)(['name' => 'Acme']);
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, true, null, false));

    $outcome = ($this->classify)(['companies.import', 'companies.create', 'companies.update']);

    expect($outcome['status'])->toBe('error')
        ->and($outcome['message'])->toBe(__('i18n.backend.support.import.import_row_classifier.the_record_was_not_found'));
});

it('refuses a relation column the caller may not write before it ever looks at the target type', function (): void {
    new FakeFieldVisibilityResolver([], ['contacts']);
    ($this->rowYields)(['name' => 'Acme'], [['values' => ['EXT-9'], 'field' => $this->relationField]]);
    $this->locator->shouldNotReceive('locate');
    $this->registry->shouldNotReceive('find');

    $outcome = ($this->classify)(['companies.import', 'companies.create', 'contacts.view']);

    expect($outcome['status'])->toBe('error')
        ->and($outcome['message'])->toBe(__('i18n.backend.support.import.import_row_classifier.you_lack_write_permission_for_the_field', ['value1' => 'contacts']));
});

it('reports a relation target as not found when the caller may not view the target type', function (): void {
    new FakeFieldVisibilityResolver;
    $this->fieldLookup->withRelationshipTarget(ModelStub::ulid('rel'), (string) $this->targetType->getKey());
    ($this->rowYields)(['name' => 'Acme'], [['values' => ['EXT-9'], 'field' => $this->relationField]]);
    $this->registry->shouldReceive('find')->andReturn($this->targetType);
    $this->exchangeIdentity->shouldReceive('resolve')->andReturn($this->existing);
    $this->locator->shouldNotReceive('locate');

    $outcome = ($this->classify)(['companies.import', 'companies.create']);

    expect($outcome['status'])->toBe('error')
        ->and($outcome['message'])->toBe(__('i18n.backend.support.import.import_row_classifier.relationship_the_linked_record_was_not_found', ['value1' => 'contacts', 'value2' => 'EXT-9']));
});

it('accepts a relation target once the caller may view the target type and the target resolves', function (): void {
    new FakeFieldVisibilityResolver;
    $this->fieldLookup->withRelationshipTarget(ModelStub::ulid('rel'), (string) $this->targetType->getKey());
    ($this->rowYields)(['name' => 'Acme'], [['values' => ['EXT-9'], 'field' => $this->relationField]]);
    $this->registry->shouldReceive('find')->andReturn($this->targetType);
    $this->exchangeIdentity->shouldReceive('resolve')->andReturn($this->existing);
    $this->locator->shouldReceive('locate')->andReturnNull();

    $outcome = ($this->classify)(['companies.import', 'companies.create', 'contacts.view']);

    expect($outcome['status'])->toBe('insert')
        ->and($outcome['message'])->toBeNull();
});

it('reports a relation target that the caller may view but that does not resolve as not found', function (): void {
    new FakeFieldVisibilityResolver;
    $this->fieldLookup->withRelationshipTarget(ModelStub::ulid('rel'), (string) $this->targetType->getKey());
    ($this->rowYields)(['name' => 'Acme'], [['values' => ['EXT-9'], 'field' => $this->relationField]]);
    $this->registry->shouldReceive('find')->andReturn($this->targetType);
    $this->exchangeIdentity->shouldReceive('resolve')->andReturnNull();
    $this->locator->shouldNotReceive('locate');

    $outcome = ($this->classify)(['companies.import', 'companies.create', 'contacts.view']);

    expect($outcome['status'])->toBe('error')
        ->and($outcome['message'])->toContain('EXT-9');
});

it('refuses to insert next to a record that a unique field already claims', function (): void {
    new FakeFieldVisibilityResolver;
    $uniqueField = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-vat'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => 'vat_id',
        'field_type' => FieldType::TextShort,
    ]);

    ($this->rowYields)(['name' => 'Acme']);
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, false, $uniqueField, true));

    $outcome = ($this->classifyIn)(['companies.import', 'companies.create'], ImportDuplicateMode::Insert);

    expect($outcome['status'])->toBe('error')
        ->and($outcome['message'])->toBe(__('i18n.backend.support.import.import_row_classifier.the_field_is_marked_as_unique_the_row_cannot', ['value1' => 'vat_id']));
});

it('treats a duplicate that no identity column and no unique field claims by the duplicate mode of the run', function (ImportDuplicateMode $mode, array $abilities, string $status): void {
    new FakeFieldVisibilityResolver;
    ($this->rowYields)(['name' => 'Acme']);
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, false, null, true));

    $outcome = ($this->classifyIn)($abilities, $mode);

    expect($outcome['status'])->toBe($status)
        ->and($outcome['message'])->toBeNull();
})->with([
    'skips it when the run skips duplicates' => [ImportDuplicateMode::Skip, ['companies.import', 'companies.create', 'companies.update'], 'skip'],
    'inserts a second record when the run inserts' => [ImportDuplicateMode::Insert, ['companies.import', 'companies.create'], 'insert'],
    'overwrites it when the run upserts' => [ImportDuplicateMode::Upsert, ['companies.import', 'companies.update'], 'update'],
]);
