<?php

declare(strict_types=1);

use App\Actions\Engine\RestoreRecordAction;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordValidator;
use App\Support\Engine\RollupOwnerStarter;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeRegistry;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('restore-companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('trashed-company'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'data' => ['vat_id' => 'DE123'],
        'deleted_at' => '2026-10-30 10:00:00',
        'version' => 4,
    ]);

    $objectTypes = Mockery::mock(StaticObjectTypeRegistry::class, [])->makePartial();
    $objectTypes->shouldReceive('forRecord')->with($this->record)->andReturn($this->objectType);
    app()->instance(ObjectTypeRegistry::class, $objectTypes);

    $this->validator = Mockery::mock(RecordValidator::class);
    app()->instance(RecordValidator::class, $this->validator);

    $this->auditRecorder = Mockery::mock(AuditRecorder::class);
    app()->instance(AuditRecorder::class, $this->auditRecorder);

    app()->instance(RollupOwnerStarter::class, Mockery::mock(RollupOwnerStarter::class)->shouldIgnoreMissing());

    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to restore a record whose unique value another record took meanwhile and writes nothing', function (): void {
    $this->validator->shouldReceive('assertUniqueUnderLock')
        ->once()
        ->with($this->objectType, ['vat_id' => 'DE123'], (string) $this->tenant->getKey(), $this->record)
        ->andThrow(ValidationException::withMessages(['data.vat_id' => 'USt-ID ist bereits vergeben.']));
    $this->auditRecorder->shouldNotReceive('record');

    $errors = [];

    try {
        app(RestoreRecordAction::class)->execute($this->record);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }

    expect($errors)->toHaveKey('record')
        ->and($errors['record'][0])->toContain('Wiederherstellen nicht möglich')
        ->and($errors['record'][0])->toContain('USt-ID ist bereits vergeben.')
        ->and($this->connection->writtenStatements)->toBe([])
        ->and($this->connection->openedTransactions)->toBe(1);
});

it('restores a record whose unique values are still free and audits the restore', function (): void {
    $this->validator->shouldReceive('assertUniqueUnderLock')->once();
    $this->auditRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (CustomRecord $record, array $old, array $new): bool => $record === $this->record
            && $old['deleted_at'] !== null
            && $new === ['deleted_at' => null]);

    app(RestoreRecordAction::class)->execute($this->record);

    expect($this->connection->writtenSqlOf('custom_records'))->not->toBe([]);
});
