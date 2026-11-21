<?php

declare(strict_types=1);

use App\Actions\Engine\UpdateRecordAction;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\RecordValidator;
use App\Support\Modules\RecordExtensions;
use App\Support\Watchers\WatcherAutoSubscriber;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('products'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'products',
    ], [
        'fieldDefinitions' => ModelStub::collection(FieldDefinition::class, [
            ['id' => ModelStub::ulid('products-field-name'), 'object_type_id' => ModelStub::ulid('products'), 'key' => 'name', 'field_type' => FieldType::TextShort->value],
            ['id' => ModelStub::ulid('products-field-price'), 'object_type_id' => ModelStub::ulid('products'), 'key' => 'price', 'field_type' => FieldType::Money->value],
        ]),
    ]);
    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('product-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'version' => 3,
        'data' => ['name' => 'Pro', 'price' => '12'],
    ], ['objectType' => $this->objectType]);

    AccessContext::actAs(AccessContext::user($this->tenant));
    $resolver = Mockery::mock(FieldVisibilityResolver::class);
    $resolver->shouldReceive('forbiddenWriteFieldKeys')->andReturn([]);
    app()->instance(FieldVisibilityResolver::class, $resolver);

    $this->recordValidator = Mockery::mock(RecordValidator::class);
    $registry = Mockery::mock(ObjectTypeRegistry::class);
    $registry->shouldReceive('forRecord')->andReturn($this->objectType);
    $registry->shouldReceive('fields')->andReturn($this->objectType->fieldDefinitions);
    $this->extensions = Mockery::mock(RecordExtensions::class);
    $this->extensions->shouldReceive('suppliedValues')->with($this->record)->andReturn(['price' => '49.00']);

    $this->action = new UpdateRecordAction(
        $registry,
        $this->recordValidator,
        Mockery::mock(AuditRecorder::class),
        Mockery::mock(WatcherAutoSubscriber::class),
        Mockery::mock(ComputedFieldWriter::class),
        $this->extensions,
        Mockery::mock(RecordSearchText::class)->shouldIgnoreMissing(),
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
});

it('refuses to change a field another app supplies for the record and names the field', function (): void {
    $this->recordValidator->shouldNotReceive('validate');

    expect(fn (): CustomRecord => $this->action->execute($this->record, ['version' => 3, 'data' => ['name' => 'Pro', 'price' => '99']]))
        ->toThrow(function (ValidationException $exception): void {
            expect(array_keys($exception->errors()))->toBe(['data.price'])
                ->and($exception->errors()['data.price'][0])->toBe(__('i18n.backend.actions.engine.update_record_action.the_value_is_maintained_by_another_app'));
        });
});

it('lets the supplied value itself and every other field through to the validation', function (): void {
    $this->recordValidator->shouldReceive('validate')->once()
        ->withArgs(fn (ObjectType $type, array $data): bool => $data['price'] === '49' && $data['name'] === 'Pro Plus')
        ->andThrow(ValidationException::withMessages(['data.name' => 'reached']));

    expect(fn (): CustomRecord => $this->action->execute($this->record, ['version' => 3, 'data' => ['name' => 'Pro Plus', 'price' => '49']]))
        ->toThrow(ValidationException::class, 'reached');
});
