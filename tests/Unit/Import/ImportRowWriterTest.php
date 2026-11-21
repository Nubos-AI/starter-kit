<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\LinkRecordsAction;
use App\Actions\Engine\UpdateRecordAction;
use App\DTOs\Import\ImportRecordMatch;
use App\DTOs\Import\ImportRowValues;
use App\Enums\Import\ImportDuplicateMode;
use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RecordExchangeIdentity;
use App\Support\Import\ImportErrorReport;
use App\Support\Import\ImportRecordLocator;
use App\Support\Import\ImportRowClassifier;
use App\Support\Import\ImportRowMapper;
use App\Support\Import\ImportRowWriter;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Cache::flush();

    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->importJobId = ModelStub::ulid('import-job');

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
    ]);

    $this->existing = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('existing'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
    ]);

    $this->classifier = Mockery::mock(ImportRowClassifier::class);
    $this->mapper = Mockery::mock(ImportRowMapper::class);
    $this->mapper->shouldReceive('map')
        ->andReturn(new ImportRowValues('EXT-1', null, ['name' => 'Acme', 'city' => null], []))
        ->byDefault();
    $this->locator = Mockery::mock(ImportRecordLocator::class);
    $this->createAction = Mockery::mock(CreateRecordAction::class);
    $this->updateAction = Mockery::mock(UpdateRecordAction::class);
    $this->linkAction = Mockery::mock(LinkRecordsAction::class);
    $this->exchangeIdentity = Mockery::mock(RecordExchangeIdentity::class);
    $this->fieldLookup = Mockery::mock(ObjectTypeFieldLookup::class);

    $this->writer = new ImportRowWriter(
        $this->classifier,
        $this->mapper,
        $this->locator,
        $this->createAction,
        $this->updateAction,
        $this->linkAction,
        $this->exchangeIdentity,
        $this->fieldLookup,
    );

    $this->storedRow = [
        'id' => (string) $this->existing->getKey(),
        'tenant_id' => $this->tenantId,
        'object_type_id' => (string) $this->objectType->getKey(),
        'version' => 7,
        'data' => '{"name":"Acme"}',
    ];

    /** @var callable():StaticQueryConnection */
    $this->connection = fn (): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql): array => str_contains($sql, 'from "custom_records"') ? [$this->storedRow] : [],
        static fn (): int => 1,
    );

    /** @var callable(array<string, mixed>, list<string>):ImportRowValues */
    $this->valuesLinking = fn (array $config, array $targets): ImportRowValues => new ImportRowValues(
        'EXT-1',
        null,
        ['name' => 'Acme'],
        ['partner' => [
            'values' => $targets,
            'field' => ModelStub::make(FieldDefinition::class, [
                'id' => ModelStub::ulid('partner-field'),
                'tenant_id' => $this->tenantId,
                'object_type_id' => (string) $this->objectType->getKey(),
                'key' => 'partner',
                'config' => $config,
            ]),
        ]],
    );

    /** @var callable(string, ?string):void */
    $this->classifiesAs = function (string $status, ?string $message = null): void {
        $this->classifier->shouldReceive('classify')->andReturn([
            'status' => $status,
            'row' => 4,
            'message' => $message,
            'identity' => 'EXT-1',
        ]);
    };

    /** @var callable():string */
    $this->write = fn (): string => $this->writer->write(
        ['name' => 'Acme'],
        $this->objectType,
        ['columns' => ['name' => 'name']],
        ImportDuplicateMode::Upsert,
        $this->tenantId,
        4,
        $this->importJobId,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('skips a duplicate row without writing anything and without an error row', function (): void {
    ($this->classifiesAs)('skip');
    $this->createAction->shouldNotReceive('execute');
    $this->updateAction->shouldNotReceive('execute');

    expect(($this->write)())->toBe('skipped')
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([]);
});

it('turns a refused row into an error row that names the row number, the record and the reason', function (): void {
    ($this->classifiesAs)('error', 'Sie dürfen keine Datensätze dieses Typs anlegen.');
    $this->createAction->shouldNotReceive('execute');

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([[
            'row' => 4,
            'reason' => 'Sie dürfen keine Datensätze dieses Typs anlegen.',
            'identity' => 'EXT-1',
        ]]);
});

it('reports an error row even when the refusal carried no message', function (): void {
    ($this->classifiesAs)('error');

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId)[0]['reason'])
        ->toBe(__('i18n.backend.support.import.import_row_writer.the_row_could_not_be_processed'));
});

it('refuses to update a record the importer can no longer see and never asks the update action', function (): void {
    ($this->classifiesAs)('update');
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, true, null, false));
    $this->updateAction->shouldNotReceive('execute');

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId)[0]['reason'])
        ->toBe(__('i18n.backend.support.import.import_row_writer.the_record_to_update_was_not_found'));
});

it('refuses to update a record that vanished between classification and write', function (): void {
    ($this->classifiesAs)('update');
    $this->locator->shouldReceive('locate')->andReturnNull();
    $this->updateAction->shouldNotReceive('execute');

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId))->toHaveCount(1);
});

it('lets an accepted new row through to the database and writes no error row', function (): void {
    ($this->classifiesAs)('insert');

    expect(WriteAttempt::reachedTheDatabase($this->write))->toBeTrue()
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([]);
});

it('lets an accepted update of a visible record through to the database', function (): void {
    ($this->classifiesAs)('update');
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, true, null, true));

    expect(WriteAttempt::reachedTheDatabase($this->write))->toBeTrue()
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([]);
});

it('retries the update while another writer keeps winning the version race and reports the row as updated', function (): void {
    ($this->classifiesAs)('update');
    ($this->connection)();
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, true, null, true));

    $attempts = 0;
    $this->updateAction->shouldReceive('execute')->times(3)->andReturnUsing(function () use (&$attempts): CustomRecord {
        $attempts++;

        if ($attempts < 3) {
            throw new StaleRecordException;
        }

        return $this->existing;
    });

    expect(($this->write)())->toBe('updated')
        ->and($attempts)->toBe(3)
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([]);
});

it('gives up after the last attempt and turns the lost version race into an error row', function (): void {
    ($this->classifiesAs)('update');
    ($this->connection)();
    $this->locator->shouldReceive('locate')->andReturn(new ImportRecordMatch($this->existing, true, null, true));
    $this->updateAction->shouldReceive('execute')->times(5)->andThrow(new StaleRecordException);

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId)[0]['reason'])
        ->toBe(__('i18n.backend.support.import.import_row_writer.the_record_could_not_be_updated_version_conflict'));
});

it('refuses a row whose relation field carries no relationship type and never links anything', function (): void {
    ($this->classifiesAs)('insert');
    ($this->connection)();
    $this->mapper->shouldReceive('map')->andReturn(($this->valuesLinking)([], ['TARGET-1']));
    $this->createAction->shouldReceive('execute')->once()->andReturn($this->existing);
    $this->linkAction->shouldNotReceive('execute');

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId)[0]['reason'])
        ->toBe(__('i18n.backend.support.import.import_row_writer.relationship_the_relationship_configuration_is_incomplete', ['value1' => 'partner']));
});

it('refuses a row that points at a linked record nobody can resolve and never links anything', function (): void {
    ($this->classifiesAs)('insert');
    ($this->connection)();
    $this->mapper->shouldReceive('map')->andReturn(($this->valuesLinking)(['relationship_type_id' => 'REL-1'], ['TARGET-1']));
    $this->createAction->shouldReceive('execute')->once()->andReturn($this->existing);
    $this->fieldLookup->shouldReceive('relationshipTarget')->with('REL-1')->andReturn('TYPE-1');
    $this->exchangeIdentity->shouldReceive('resolve')->with('TYPE-1', 'TARGET-1', $this->tenantId)->andReturnNull();
    $this->linkAction->shouldNotReceive('execute');

    expect(($this->write)())->toBe('error')
        ->and(ImportErrorReport::errors($this->importJobId)[0]['reason'])
        ->toBe(__('i18n.backend.support.import.import_row_writer.relationship_the_linked_record_was_not_found', ['value1' => 'partner', 'value2' => 'TARGET-1']));
});

it('links the created record to every resolved target and asks the relationship target only once', function (): void {
    ($this->classifiesAs)('insert');
    ($this->connection)();
    $this->mapper->shouldReceive('map')->andReturn(($this->valuesLinking)(['relationship_type_id' => 'REL-1'], ['TARGET-1', 'TARGET-2']));
    $this->createAction->shouldReceive('execute')->once()->andReturn($this->existing);
    $this->fieldLookup->shouldReceive('relationshipTarget')->once()->with('REL-1')->andReturn('TYPE-1');

    $target = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('link-target'),
        'tenant_id' => $this->tenantId,
        'object_type_id' => 'TYPE-1',
    ]);
    $this->exchangeIdentity->shouldReceive('resolve')->twice()->andReturn($target);

    $linked = [];
    $this->linkAction->shouldReceive('execute')->twice()->andReturnUsing(function (array $input) use (&$linked): RecordLink {
        $linked[] = $input;

        return ModelStub::make(RecordLink::class, ['tenant_id' => $this->tenantId]);
    });

    expect(($this->write)())->toBe('created')
        ->and($linked)->toHaveCount(2)
        ->and($linked[0]['from_record_id'])->toBe($this->existing->getKey())
        ->and($linked[0]['to_record_id'])->toBe($target->getKey())
        ->and(ImportErrorReport::errors($this->importJobId))->toBe([]);
});
