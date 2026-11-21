<?php

declare(strict_types=1);

use App\Actions\Engine\AppendFieldOptionsAction;
use App\Actions\Import\PrepareRecordImportAction;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Import\ImportMappingValidator;
use App\Support\Import\ImportReaderFactory;
use App\Support\Import\MissingOptionResolver;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\FakeFieldVisibilityResolver;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'importer'));
    $this->connection = StaticQueryConnection::install(static fn (): array => [], static fn (): int => 1);

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $fields = array_map(fn (string $key): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("field-{$key}"),
        'tenant_id' => (string) $this->tenant->getKey(),
        'object_type_id' => (string) $this->objectType->getKey(),
        'key' => $key,
    ]), ['name', 'status']);

    $this->readers = Mockery::mock(ImportReaderFactory::class);
    $this->readers->shouldReceive('uploadDisk')->andReturn('local');
    $this->resolver = Mockery::mock(MissingOptionResolver::class);
    $this->append = Mockery::mock(AppendFieldOptionsAction::class);

    $this->action = new PrepareRecordImportAction(
        $this->readers,
        $this->resolver,
        $this->append,
        new ImportMappingValidator(StaticObjectTypeFieldLookup::carrying((string) $this->objectType->getKey(), $fields)),
    );

    $this->input = static fn (array $columns): array => [
        'path' => 'imports/tenant/user/upload.csv',
        'format' => 'csv',
        'mapping' => ['columns' => $columns],
        'duplicate_mode' => 'skip',
        'missing_option_mode' => 'create',
    ];
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses to execute a mapping onto a field the importer may not write before it reads the file or adds an option', function (): void {
    new FakeFieldVisibilityResolver([], ['status']);
    $this->resolver->shouldReceive('effectiveMode')->andReturnArg(0);
    $this->readers->shouldReceive('make')->never();
    $this->append->shouldReceive('execute')->never();

    try {
        $this->action->execute($this->objectType, $this->user, ($this->input)(['Name' => 'name', 'Status' => 'status']));
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe(['mapping'])
            ->and($this->connection->writtenStatements)->toBe([]);

        return;
    }

    throw new RuntimeException('The import ran with a forbidden mapping target.');
});

it('refuses to execute a mapping onto a field the object type does not define', function (): void {
    new FakeFieldVisibilityResolver;
    $this->resolver->shouldReceive('effectiveMode')->andReturnArg(0);
    $this->readers->shouldReceive('make')->never();
    $this->append->shouldReceive('execute')->never();

    expect(fn () => $this->action->execute($this->objectType, $this->user, ($this->input)(['Gehalt' => 'salary'])))
        ->toThrow(ValidationException::class);
});
