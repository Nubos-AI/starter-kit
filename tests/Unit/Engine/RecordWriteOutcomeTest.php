<?php

declare(strict_types=1);

use App\Actions\Engine\UpdateRecordAction;
use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordValidator;
use App\Support\Modules\RecordExtensions;
use App\Support\Watchers\WatcherAutoSubscriber;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
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
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'version' => 3,
        'data' => [],
    ], ['objectType' => $this->objectType]);

    AccessContext::actAs(AccessContext::user($this->tenant));

    $resolver = Mockery::mock(FieldVisibilityResolver::class);
    $resolver->shouldReceive('forbiddenWriteFieldKeys')->andReturn([]);
    app()->instance(FieldVisibilityResolver::class, $resolver);

    $validator = Mockery::mock(RecordValidator::class);
    $validator->shouldReceive('validate')->andReturn(['city' => 'Karlsruhe']);

    $registry = Mockery::mock(ObjectTypeRegistry::class);
    $registry->shouldReceive('forRecord')->andReturn($this->objectType);

    $this->auditRecorder = Mockery::mock(AuditRecorder::class);
    $this->watchers = Mockery::mock(WatcherAutoSubscriber::class);

    $this->action = new UpdateRecordAction(
        $registry,
        $validator,
        $this->auditRecorder,
        $this->watchers,
        Mockery::mock(ComputedFieldWriter::class)->shouldIgnoreMissing(),
        Mockery::mock(RecordExtensions::class)->shouldIgnoreMissing(),
    );

    $this->storedRow = [
        'id' => (string) $this->record->getKey(),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'version' => 4,
        'data' => '{"city":"Karlsruhe"}',
    ];

    /** @var callable(int):StaticQueryConnection */
    $this->writeTouching = fn (int $rows): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "custom_records"') ? [$this->storedRow] : [],
        static fn (): int => $rows,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
});

it('refuses the write when the expected version no longer matches the stored one', function (): void {
    $connection = ($this->writeTouching)(0);
    $this->auditRecorder->shouldNotReceive('record');

    expect(fn (): CustomRecord => $this->action->execute($this->record, [
        'version' => 3,
        'data' => ['city' => 'Karlsruhe'],
    ]))->toThrow(StaleRecordException::class);

    expect($connection->writtenSqlOf('custom_records'))->toHaveCount(1)
        ->and($connection->writtenStatements[0]['sql'])->toContain('"version" = ?')
        ->and($connection->writtenStatements[0]['bindings'])->toContain(3);
});

it('records the audit trail under the next version once the write took effect', function (): void {
    ($this->writeTouching)(1);

    $this->auditRecorder->shouldReceive('record')
        ->once()
        ->withArgs(fn (CustomRecord $record, array $old, array $new, int $version): bool => $version === 4);

    $this->action->execute($this->record, ['version' => 3, 'data' => ['city' => 'Karlsruhe']]);
});
