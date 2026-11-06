<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\DuplicateRecordAction;
use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Models\CustomRecord;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Modules\RecordExtensions;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->objectTypeId = ModelStub::ulid('employees');

    StaticQueryConnection::install(static fn (): array => []);

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

    $this->action = new DuplicateRecordAction($extensions, $this->createRecord, Mockery::mock(SyncRecordCollaboratorsAction::class));

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
