<?php

declare(strict_types=1);

use App\Models\ImportJob;
use App\Support\Import\CsvReader;
use App\Support\Import\PreparedImportRows;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->rows = new PreparedImportRows;
    $this->job = ModelStub::make(ImportJob::class, [
        'id' => ModelStub::ulid('prepared-job'),
        'tenant_id' => ModelStub::ulid('prepared-tenant'),
        'user_id' => ModelStub::ulid('prepared-user'),
        'source_disk' => 'local',
        'total_rows' => 510,
    ]);
    $this->source = static function (): Generator {
        for ($index = 0; $index < 510; $index++) {
            yield ['Name' => "Record {$index}", 'Amount' => 12.5, 'Empty' => null];
        }
    };
});

it('reads arbitrary ranges across prepared part boundaries without opening the source again', function (): void {
    $this->rows->prepare($this->job, $this->source);
    $this->rows->prepare($this->job, static fn (): never => throw new RuntimeException('The original must not reopen.'));

    $rows = iterator_to_array($this->rows->rows($this->job, 248, 5));

    expect(array_keys($rows))->toBe([248, 249, 250, 251, 252])
        ->and($rows[250])->toBe(['Name' => 'Record 250', 'Amount' => 12.5, 'Empty' => null])
        ->and(iterator_to_array($this->rows->rows($this->job, 508, 20)))->toHaveCount(2);
});

it('reads a late part independently of earlier parts', function (): void {
    $this->rows->prepare($this->job, $this->source);

    foreach (Storage::disk('local')->allFiles() as $path) {
        if (str_ends_with($path, '/0.json')) {
            Storage::disk('local')->delete($path);
        }
    }

    expect(iterator_to_array($this->rows->rows($this->job, 500, 1))[500]['Name'])->toBe('Record 500');
});

it('never publishes a partial normalization and can retry from a clean generation', function (): void {
    $broken = static function (): Generator {
        for ($index = 0; $index < 251; $index++) {
            yield ['Name' => 'Partial'];
        }

        throw new RuntimeException('Source failed');
    };

    expect(fn () => $this->rows->prepare($this->job, $broken))->toThrow(RuntimeException::class, 'Source failed')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->rows->prepare($this->job, $this->source);

    expect(iterator_to_array($this->rows->rows($this->job, 0, 1))[0]['Name'])->toBe('Record 0');
});

it('rejects a source whose row count changed since preparation', function (): void {
    $this->job->total_rows = 511;

    expect(fn () => $this->rows->prepare($this->job, $this->source))->toThrow(RuntimeException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('preserves quoted multiline csv values across normalized chunks', function (): void {
    Storage::disk('local')->put('source.csv', "Name,Note\nAcme,\"first\nsecond\"\nGlobex,\"quote \"\"inside\"\"\"\n");
    $this->job->total_rows = 2;
    $reader = new CsvReader(Storage::disk('local')->path('source.csv'));
    $this->rows->prepare($this->job, fn (): Generator => $reader->rows());
    $rows = iterator_to_array($this->rows->rows($this->job, 0, 2));

    expect($rows[0]['Note'])->toBe("first\nsecond")
        ->and($rows[1]['Note'])->toBe('quote "inside"');

    $this->rows->delete($this->job);

    expect(Storage::disk('local')->allFiles())->toBe(['source.csv']);
});
