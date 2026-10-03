<?php

declare(strict_types=1);

use App\Actions\Engine\ChangeFieldTypeAction;
use App\Actions\Engine\SnapshotDefinitionAction;
use App\Enums\CustomFields\FieldType;
use App\Exceptions\StaleRecordException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\RouteShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    /** @var callable(bool):ObjectType */
    $this->objectType = fn (bool $isSystem): ObjectType => ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('type-change-object-type-'.($isSystem ? 'system' : 'own')),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'deals',
        'key' => 'deals',
        'is_system' => $isSystem,
    ]);

    /** @var callable(FieldType, bool):FieldDefinition */
    $this->field = function (FieldType $type, bool $onSystemType = false): FieldDefinition {
        $objectType = ($this->objectType)($onSystemType);

        return ModelStub::make(FieldDefinition::class, [
            'id' => ModelStub::ulid('type-change-field'),
            'tenant_id' => $this->tenant->getKey(),
            'object_type_id' => $objectType->getKey(),
            'key' => 'amount',
            'field_type' => $type,
        ], ['objectType' => $objectType]);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to change a field of a system object type and reads no record for it', function (): void {
    $field = ($this->field)(FieldType::TextShort, true);

    expect(fn (): array => app(ChangeFieldTypeAction::class)->execute($field, FieldType::Number))
        ->toThrow(AuthorizationException::class)
        ->and(QueryShape::attemptedBy(function () use ($field): void {
            try {
                app(ChangeFieldTypeAction::class)->execute($field, FieldType::Number);
            } catch (AuthorizationException) {
                return;
            }
        }))->toBeNull();
});

it('refuses a change that leaves or enters a type no conversion can carry', function (FieldType $from, FieldType $to): void {
    expect(fn (): array => app(ChangeFieldTypeAction::class)->execute(($this->field)($from), $to))
        ->toThrow(ValidationException::class);
})->with([
    'into a computed field' => [FieldType::TextShort, FieldType::Computed],
    'into a rollup' => [FieldType::TextShort, FieldType::Rollup],
    'into a relation' => [FieldType::TextShort, FieldType::RelationHasMany],
    'out of a file field' => [FieldType::File, FieldType::TextShort],
    'out of a geo address' => [FieldType::GeoAddress, FieldType::TextShort],
]);

it('reads no record when it already knows the conversion is impossible', function (): void {
    $shape = QueryShape::attemptedBy(function (): void {
        try {
            app(ChangeFieldTypeAction::class)->execute(($this->field)(FieldType::TextShort), FieldType::Computed);
        } catch (ValidationException) {
            return;
        }
    });

    expect($shape)->toBeNull();
});

it('keeps the derived and the referencing types out of the convertible catalogue', function (): void {
    $changeable = array_values(array_filter(
        FieldType::cases(),
        static fn (FieldType $type): bool => $type->isTypeChangeable(),
    ));

    expect(FieldType::TextShort->isTypeChangeable())->toBeTrue()
        ->and(FieldType::Number->isTypeChangeable())->toBeTrue()
        ->and($changeable)->not->toContain(FieldType::Computed)
        ->and($changeable)->not->toContain(FieldType::Rollup)
        ->and($changeable)->not->toContain(FieldType::File)
        ->and($changeable)->not->toContain(FieldType::GeoAddress)
        ->and($changeable)->not->toContain(FieldType::RelationHasMany)
        ->and($changeable)->not->toContain(FieldType::RelationManyToMany);
});

it('guards the type change route with the object type update permission and the custom field capability', function (): void {
    $route = RouteShape::named('engine.object-types.fields.type');

    expect($route->hasDeclaredMiddleware('permission:object-types.update'))->toBeTrue()
        ->and($route->hasDeclaredMiddleware('capability:custom_fields'))->toBeTrue()
        ->and($route->handledBy())->toContain('FieldTypesController@update')
        ->and($route->methods())->toContain('PUT');
});

it('refuses the conversion of a record whose version moved on and records no audit trail for it', function (): void {
    $field = ($this->field)(FieldType::TextShort);
    $objectTypeId = (string) $field->objectType->getKey();

    $connection = StaticQueryConnection::install(
        fn (string $sql): array => match (true) {
            str_contains($sql, '"id" > ?') => [],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => ModelStub::ulid('type-change-record'),
                'tenant_id' => (string) $this->tenant->getKey(),
                'object_type_id' => $objectTypeId,
                'version' => 9,
                'data' => '{"amount":"42"}',
            ]],
            str_contains($sql, 'from "object_types"') => [[
                'id' => $objectTypeId,
                'tenant_id' => (string) $this->tenant->getKey(),
                'slug' => 'deals',
                'key' => 'deals',
                'is_system' => false,
            ]],
            default => [],
        },
        static fn (): int => 0,
    );

    $auditRecorder = Mockery::mock(AuditRecorder::class);
    $auditRecorder->shouldNotReceive('record');

    $snapshot = Mockery::mock(SnapshotDefinitionAction::class);
    $snapshot->shouldReceive('execute')->andReturn(ModelStub::make(ObjectTypeDefinitionVersion::class, [
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $objectTypeId,
    ]));

    $action = new ChangeFieldTypeAction(
        app(FieldTypeRegistry::class),
        $auditRecorder,
        $snapshot,
        app(SystemObjectTypeGuard::class),
    );

    expect(fn (): array => $action->execute($field, FieldType::Number))
        ->toThrow(StaleRecordException::class)
        ->and($connection->writtenSqlOf('custom_records'))->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toContain('"version" = ?')
        ->and($connection->writtenStatements[0]['bindings'])->toContain(9);

    StaticQueryConnection::uninstall();
});
