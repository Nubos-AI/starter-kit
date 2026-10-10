<?php

declare(strict_types=1);

use App\Enums\Import\ImportDuplicateMode;
use App\Enums\Import\ImportMissingOptionMode;
use App\Models\ObjectType;
use App\Support\Import\CsvReader;
use App\Support\Import\ImportDryRunService;
use App\Support\Import\ImportRecordLocator;
use App\Support\Import\ImportRowClassifier;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->user = AccessContext::actAs(AccessContext::user($this->tenant, [], 'importer'));

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => (string) $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->classifier = Mockery::mock(ImportRowClassifier::class);
    $this->locator = Mockery::mock(ImportRecordLocator::class);
    $this->locator->shouldReceive('detectsDuplicates')->andReturnTrue()->byDefault();

    $this->service = new ImportDryRunService($this->classifier, $this->locator);

    /** @var callable(int):CsvReader */
    $this->readerOf = static function (int $rows): CsvReader {
        $reader = Mockery::mock(CsvReader::class);
        $reader->shouldReceive('rows')->andReturnUsing(static function () use ($rows): Generator {
            for ($index = 0; $index < $rows; $index++) {
                yield ['name' => 'Acme'];
            }
        });

        return $reader;
    };

    /** @var callable(list<string>):void */
    $this->classifiesAs = function (array $statuses): void {
        $index = 0;

        $this->classifier->shouldReceive('classify')->andReturnUsing(
            static function () use ($statuses, &$index): array {
                $status = $statuses[$index];
                $index++;

                return [
                    'status' => $status,
                    'row' => $index + 1,
                    'message' => $status === 'error' ? "Zeile {$index} ist fehlerhaft." : null,
                    'identity' => null,
                ];
            },
        );
    };

    /** @var callable(int):array{new: int, updates: int, errors: int, duplicateDetection: bool, sampleErrors: list<array{row: int, message: string}>} */
    $this->runOver = fn (int $rows): array => $this->service->run(
        $this->objectType,
        $this->user,
        ($this->readerOf)($rows),
        ['columns' => ['name' => 'name']],
        ImportDuplicateMode::Upsert,
        ImportMissingOptionMode::Error,
    );
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('counts inserts, updates and errors of a mixed file and skips nothing silently', function (): void {
    ($this->classifiesAs)(['insert', 'update', 'error', 'insert', 'skip']);

    $report = ($this->runOver)(5);

    expect($report['new'])->toBe(2)
        ->and($report['updates'])->toBe(1)
        ->and($report['errors'])->toBe(1)
        ->and($report['sampleErrors'])->toHaveCount(1);
});

it('numbers the reported rows from the first data row after the header', function (): void {
    ($this->classifiesAs)(['error']);

    expect(($this->runOver)(1)['sampleErrors'][0])->toBe(['row' => 2, 'message' => 'Zeile 1 ist fehlerhaft.']);
});

it('caps the sampled errors while it keeps counting all of them', function (): void {
    ($this->classifiesAs)(array_fill(0, 60, 'error'));

    $report = ($this->runOver)(60);

    expect($report['errors'])->toBe(60)
        ->and($report['sampleErrors'])->toHaveCount(50);
});

it('reports whether the object type can detect duplicates at all', function (): void {
    ($this->classifiesAs)(['insert']);

    expect(($this->runOver)(1)['duplicateDetection'])->toBeTrue();
});

it('reports that duplicates cannot be detected when the object type has no duplicate key', function (): void {
    $this->locator->shouldReceive('detectsDuplicates')->andReturnFalse();
    ($this->classifiesAs)(['insert']);

    expect(($this->runOver)(1)['duplicateDetection'])->toBeFalse();
});

it('counts an empty file as nothing at all', function (): void {
    $report = ($this->runOver)(0);

    expect($report['new'])->toBe(0)
        ->and($report['updates'])->toBe(0)
        ->and($report['errors'])->toBe(0)
        ->and($report['sampleErrors'])->toBe([]);
});
