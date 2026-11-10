<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\CreateRecordWithCollaboratorsAction;
use App\Actions\Engine\DuplicateRecordAction;
use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Modules\RecordExtensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('employees');

    $this->watcherRows = [];

    $this->connection = StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "record_watchers"') ? $this->watcherRows : [],
        static fn (): int => 1,
    );

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('employee-record'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => $this->objectTypeId,
        'team_id' => null,
        'owner_id' => null,
        'data' => ['name' => 'Ada', 'salary' => 90000],
    ]);

    $this->forbiddenWriteKeys = ['salary'];
    $fieldVisibility = Mockery::mock(FieldVisibilityResolver::class);
    $fieldVisibility->shouldReceive('forbiddenWriteFieldKeys')
        ->with(Mockery::type(User::class), $this->objectTypeId)
        ->andReturnUsing(fn (): array => $this->forbiddenWriteKeys);
    app()->instance(FieldVisibilityResolver::class, $fieldVisibility);

    $extensions = Mockery::mock(RecordExtensions::class);
    $extensions->shouldReceive('duplicateAttributes')->andReturn([]);

    $this->createRecord = Mockery::mock(CreateRecordAction::class);
    $this->createRecord->shouldNotReceive('executeWithoutProtocol');

    $this->syncCollaborators = Mockery::mock(SyncRecordCollaboratorsAction::class);

    $this->fieldLookup = StaticObjectTypeFieldLookup::carrying($this->objectTypeId, [
        ModelStub::make(FieldDefinition::class, ['object_type_id' => $this->objectTypeId, 'key' => 'name', 'field_type' => FieldType::TextShort->value]),
        ModelStub::make(FieldDefinition::class, ['object_type_id' => $this->objectTypeId, 'key' => 'salary', 'field_type' => FieldType::Number->value]),
        ModelStub::make(FieldDefinition::class, ['object_type_id' => $this->objectTypeId, 'key' => 'contract', 'field_type' => FieldType::File->value]),
    ]);

    $this->action = new DuplicateRecordAction(
        $extensions,
        new CreateRecordWithCollaboratorsAction($this->createRecord, $this->syncCollaborators),
        $this->fieldLookup,
    );

    $this->copy = ModelStub::make(CustomRecord::class, ['id' => ModelStub::ulid('copy')]);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('copies only the fields the actor may write and records the copy in the audit trail', function (): void {
    AccessContext::actAs(AccessContext::user($this->tenant));

    $this->createRecord->shouldReceive('execute')
        ->once()
        ->withArgs(fn (array $input): bool => $input['data'] === ['name' => 'Ada']
            && $input['object_type_id'] === $this->objectTypeId)
        ->andReturn($this->copy);

    expect($this->action->execute($this->record))->toBe($this->copy);
});

it('copies every field when no user acts', function (): void {
    $this->createRecord->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (array $input): bool => $input['data'] === ['name' => 'Ada', 'salary' => 90000])
        ->andReturn($this->copy);

    $this->action->execute($this->record);
});

it('leaves file fields empty on the copy because their attachments stay with the original record', function (): void {
    $this->record->setRawAttributes([...$this->record->getAttributes(), 'data' => json_encode(['name' => 'Ada', 'contract' => ModelStub::ulid('original-attachment')])]);

    $this->createRecord->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (array $input): bool => $input['data'] === ['name' => 'Ada'])
        ->andReturn($this->copy);

    $this->action->execute($this->record);

    AccessContext::actAs(AccessContext::user($this->tenant));

    $this->createRecord->shouldReceive('execute')
        ->once()
        ->withArgs(static fn (array $input): bool => $input['data'] === ['name' => 'Ada'])
        ->andReturn($this->copy);

    $this->action->execute($this->record);
});

it('rolls the copy back when a collaborator of the original is refused so a retry leaves no orphaned copy', function (): void {
    $this->watcherRows = [['user_id' => ModelStub::ulid('former-collaborator')]];

    $this->createRecord->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function (): CustomRecord {
            DB::table('custom_records')->insert(['id' => ModelStub::ulid('copy')]);

            return $this->copy;
        });

    $this->syncCollaborators->shouldReceive('execute')
        ->once()
        ->with($this->copy, ['collaborator_ids' => [ModelStub::ulid('former-collaborator')]])
        ->andThrow(ValidationException::withMessages(['collaborator_ids' => 'refused']));

    expect(fn (): CustomRecord => $this->action->execute($this->record))->toThrow(ValidationException::class)
        ->and($this->connection->writtenSqlOf('custom_records'))->toHaveCount(1)
        ->and(array_column($this->connection->rolledBackStatements, 'sql'))->toBe($this->connection->writtenSqlOf('custom_records'));
});
