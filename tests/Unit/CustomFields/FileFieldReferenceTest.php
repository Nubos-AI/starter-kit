<?php

declare(strict_types=1);

use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\RecordValidator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\DatabasePresenceVerifier;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->objectTypeId = ModelStub::ulid('file-reference-object-type');
    $this->recordId = ModelStub::ulid('file-reference-record');
    $this->foreignRecordId = ModelStub::ulid('file-reference-foreign-record');

    $this->attachments = [
        ['id' => ModelStub::ulid('own-contract'), 'record_id' => $this->recordId, 'field_key' => 'contract'],
        ['id' => ModelStub::ulid('own-loose-file'), 'record_id' => $this->recordId, 'field_key' => null],
        ['id' => ModelStub::ulid('own-photo'), 'record_id' => $this->recordId, 'field_key' => 'photo'],
        ['id' => ModelStub::ulid('foreign-contract'), 'record_id' => $this->foreignRecordId, 'field_key' => 'contract'],
    ];

    $connection = StaticQueryConnection::install(function (string $sql, array $bindings): array {
        if (!str_contains($sql, 'from "attachments"')) {
            return [];
        }

        $matches = array_filter($this->attachments, static fn (array $row): bool => in_array($row['id'], $bindings, true)
            && (!str_contains($sql, '"record_id"') || in_array($row['record_id'], $bindings, true))
            && (!str_contains($sql, '"field_key"')
                || in_array($row['field_key'], $bindings, true)
                || ($row['field_key'] === null && str_contains($sql, '"field_key" is null'))));

        return [['aggregate' => count($matches)]];
    });

    Validator::getFacadeRoot()->setPresenceVerifier(new DatabasePresenceVerifier($connection));

    $this->validator = app(RecordValidator::class);

    /** @var callable(array<string, mixed>):ObjectType */
    $this->typeWithFileField = fn (array $config = []): ObjectType => ModelStub::make(
        ObjectType::class,
        ['id' => $this->objectTypeId, 'tenant_id' => $this->tenantId],
        ['fieldDefinitions' => new EloquentCollection([ModelStub::make(FieldDefinition::class, [
            'object_type_id' => $this->objectTypeId,
            'key' => 'contract',
            'field_type' => FieldType::File->value,
            'config' => $config,
        ])])],
    );

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => $this->recordId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
    ]);

    /** @var callable(ObjectType, array<string, mixed>, CustomRecord|null):array<string, list<string>> */
    $this->errorsOf = function (ObjectType $type, array $data, ?CustomRecord $record): array {
        try {
            $this->validator->validate($type, $data, $this->tenantId, $record);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('accepts an attachment that belongs to the record and to the file field it is set on', function (): void {
    expect(($this->errorsOf)(($this->typeWithFileField)(), ['contract' => ModelStub::ulid('own-contract')], $this->record))->toBe([])
        ->and(($this->errorsOf)(($this->typeWithFileField)(), ['contract' => ModelStub::ulid('own-loose-file')], $this->record))->toBe([]);
});

it('refuses an attachment of another record of the same tenant', function (): void {
    expect(($this->errorsOf)(($this->typeWithFileField)(), ['contract' => ModelStub::ulid('foreign-contract')], $this->record))
        ->toHaveKey('data.contract');
});

it('refuses an attachment of the record that belongs to a different file field', function (): void {
    expect(($this->errorsOf)(($this->typeWithFileField)(), ['contract' => ModelStub::ulid('own-photo')], $this->record))
        ->toHaveKey('data.contract');
});

it('refuses every attachment while the record is still being created', function (): void {
    expect(($this->errorsOf)(($this->typeWithFileField)(), ['contract' => ModelStub::ulid('foreign-contract')], null))
        ->toHaveKey('data.contract');
});

it('refuses a foreign attachment hidden inside a multi file value', function (): void {
    $errors = ($this->errorsOf)(
        ($this->typeWithFileField)(['multiple' => true]),
        ['contract' => [ModelStub::ulid('own-contract'), ModelStub::ulid('foreign-contract')]],
        $this->record,
    );

    expect(array_keys($errors))->toBe(['data.contract.1']);
});
