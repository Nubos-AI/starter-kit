<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Http\Resources\FieldDefinitionResource;
use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\FieldPermission;
use App\Models\ObjectType;
use App\Models\Role;
use App\Policies\Engine\AttachmentPolicy;
use App\Support\Api\FilterableFieldWhitelist;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Export\ExportColumnResolver;
use App\Support\Search\SearchableObjectTypes;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeAuthorizationDirectory;
use Tests\Support\Doubles\GateSpy;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
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
    ]);

    $this->objectTypeId = (string) $this->objectType->getKey();

    $this->role = ModelStub::make(Role::class, [
        'id' => ModelStub::ulid('sales'),
        'tenant_id' => $this->tenant->getKey(),
        'name' => 'sales',
        'authority' => null,
    ]);

    /** @var callable(string, int):FieldDefinition */
    $this->field = fn (string $key, int $position): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('field-'.$key),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'key' => $key,
        'field_type' => FieldType::TextShort->value,
        'list_position' => $position,
        'is_encrypted' => false,
        'is_filterable' => true,
        'is_sortable' => true,
        'is_searchable' => true,
        'i18n_labels' => null,
        'config' => null,
    ]);

    $this->open = ($this->field)('city', 1);
    $this->managed = ($this->field)('salary', 2);

    $this->objectType->setRelation(
        'fieldDefinitions',
        new EloquentCollection([$this->open, $this->managed]),
    );

    /** @var callable(bool, bool):RoleHolder */
    $this->viewerWith = function (bool $canRead, bool $canWrite): RoleHolder {
        $grant = ModelStub::make(FieldPermission::class, [
            'id' => ModelStub::ulid('grant-salary'),
            'tenant_id' => $this->tenant->getKey(),
            'role_id' => (string) $this->role->getKey(),
            'field_definition_id' => (string) $this->managed->getKey(),
            'can_read' => $canRead,
            'can_write' => $canWrite,
        ]);

        $lookup = StaticObjectTypeFieldLookup::carrying($this->objectTypeId, [$this->open, $this->managed]);

        app()->instance(ObjectTypeFieldLookup::class, $lookup);
        app()->instance(FieldVisibilityResolver::class, new FieldVisibilityResolver(
            $lookup,
            (new FakeAuthorizationDirectory)->withFieldGrantRows([$grant]),
        ));

        return AccessContext::actAs(RoleHolder::make(
            ['tenant_id' => $this->tenant->getKey()],
            [$this->role],
            'redaction-viewer',
        ));
    };
});

afterEach(function (): void {
    app()->forgetInstance(FieldVisibilityResolver::class);
    app()->forgetInstance(ObjectTypeFieldLookup::class);
    AccessContext::forgetTenant();
});

it('drops a field without read right from the export columns', function (): void {
    $viewer = ($this->viewerWith)(false, false);

    $plan = app(ExportColumnResolver::class)->resolve($viewer, $this->objectType, []);

    expect($plan['headers'])->toContain('city')
        ->and($plan['headers'])->not->toContain('salary');
});

it('keeps the field in the export columns once the role may read it', function (): void {
    $viewer = ($this->viewerWith)(true, false);

    expect(app(ExportColumnResolver::class)->resolve($viewer, $this->objectType, [])['headers'])
        ->toContain('salary');
});

it('refuses to export a forbidden field even when it is asked for by name', function (): void {
    $viewer = ($this->viewerWith)(false, false);

    $plan = app(ExportColumnResolver::class)->resolve($viewer, $this->objectType, ['city', 'salary']);

    expect($plan['headers'])->toBe(['city']);
});

it('drops a field without read right from the filterable and sortable keys', function (): void {
    $viewer = ($this->viewerWith)(false, false);
    $whitelist = app(FilterableFieldWhitelist::class);

    expect($whitelist->filterableKeys($viewer, $this->objectTypeId))->toContain('city')
        ->and($whitelist->filterableKeys($viewer, $this->objectTypeId))->not->toContain('salary')
        ->and($whitelist->sortableKeys($viewer, $this->objectTypeId))->not->toContain('salary');
});

it('offers the field for filtering and sorting once the role may read it', function (): void {
    $viewer = ($this->viewerWith)(true, false);
    $whitelist = app(FilterableFieldWhitelist::class);

    expect($whitelist->filterableKeys($viewer, $this->objectTypeId))->toContain('salary')
        ->and($whitelist->sortableKeys($viewer, $this->objectTypeId))->toContain('salary');
});

it('drops a field without read right from the searchable attributes', function (): void {
    $viewer = ($this->viewerWith)(false, false);
    AccessContext::grant('companies.view');

    $searchable = SearchableObjectTypes::for(
        $viewer,
        null,
        new EloquentCollection([$this->objectType]),
        new EloquentCollection([$this->open, $this->managed]),
    );

    expect($searchable[$this->objectTypeId]['attributes'])->toBe(['city'])
        ->and($searchable[$this->objectTypeId]['isRestricted'])->toBeTrue();
});

it('searches the field once the role may read it', function (): void {
    $viewer = ($this->viewerWith)(true, false);
    AccessContext::grant('companies.view');

    $searchable = SearchableObjectTypes::for(
        $viewer,
        null,
        new EloquentCollection([$this->objectType]),
        new EloquentCollection([$this->open, $this->managed]),
    );

    expect($searchable[$this->objectTypeId]['attributes'])->toBe(['city', 'salary'])
        ->and($searchable[$this->objectTypeId]['isRestricted'])->toBeFalse();
});

it('hides the definition of a forbidden field from the field definition payload', function (): void {
    ($this->viewerWith)(false, false);

    $payload = FieldDefinitionResource::collection(new EloquentCollection([$this->open, $this->managed]))
        ->toArray(Request::create('/'));

    expect(array_column($payload, 'key'))->toBe(['city']);
});

it('shows the definition once the role may read the field', function (): void {
    ($this->viewerWith)(true, false);

    $payload = FieldDefinitionResource::collection(new EloquentCollection([$this->open, $this->managed]))
        ->toArray(Request::create('/'));

    expect(array_column($payload, 'key'))->toBe(['city', 'salary']);
});

it('hides an attachment that hangs on a field without read right', function (): void {
    $viewer = ($this->viewerWith)(false, false);
    GateSpy::allowing('view', 'update');

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ]);

    $attachment = ModelStub::make(Attachment::class, [
        'id' => ModelStub::ulid('salary-attachment'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $record->getKey(),
        'field_key' => 'salary',
    ], ['record' => $record]);

    expect(app(AttachmentPolicy::class)->view($viewer, $attachment))->toBeFalse();
});

it('refuses to delete an attachment on a field the role may read but not write', function (): void {
    $viewer = ($this->viewerWith)(true, false);
    GateSpy::allowing('view', 'update');

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ]);

    $attachment = ModelStub::make(Attachment::class, [
        'id' => ModelStub::ulid('salary-attachment'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $record->getKey(),
        'field_key' => 'salary',
    ], ['record' => $record]);

    $policy = app(AttachmentPolicy::class);

    expect($policy->view($viewer, $attachment))->toBeTrue()
        ->and($policy->delete($viewer, $attachment))->toBeFalse();
});

it('lets the attachment go once the role may write the field', function (): void {
    $viewer = ($this->viewerWith)(true, true);
    GateSpy::allowing('view', 'update');

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
    ]);

    $attachment = ModelStub::make(Attachment::class, [
        'id' => ModelStub::ulid('salary-attachment'),
        'tenant_id' => $this->tenant->getKey(),
        'record_id' => $record->getKey(),
        'field_key' => 'salary',
    ], ['record' => $record]);

    expect(app(AttachmentPolicy::class)->delete($viewer, $attachment))->toBeTrue();
});
