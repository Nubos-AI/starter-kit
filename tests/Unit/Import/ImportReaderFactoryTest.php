<?php

declare(strict_types=1);

use App\Support\Import\CsvReader;
use App\Support\Import\FileFormatDetector;
use App\Support\Import\ImportReaderFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Config::set('filesystems.default', 'local');
    Storage::fake('local');

    $this->tenant = AccessContext::tenant();
    $this->factory = new ImportReaderFactory(new FileFormatDetector);

    $this->importer = AccessContext::user($this->tenant, [], 'importer');
    $this->stranger = AccessContext::user($this->tenant, [], 'stranger');

    /** @var callable():void */
    $this->withoutLocalPaths = static function (): void {
        $fake = Storage::disk('local');

        Storage::set('local', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function path($path): string
            {
                throw new RuntimeException('This disk offers no local path.');
            }
        });
    };

    /** @var callable(string, string):void */
    $this->put = static function (string $path, string $contents): void {
        Storage::disk('local')->put($path, $contents);
    };

    /** @var callable(string, string):?QueryShape */
    $this->attempt = fn (string $disk, string $path): ?QueryShape => QueryShape::attemptedBy(function () use ($disk, $path): void {
        try {
            $this->factory->make($disk, $path, 'csv', null, $this->importer);
        } catch (ValidationException) {
            return;
        }
    });
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('derives the upload directory from the tenant and the user instead of the request', function (): void {
    expect($this->factory->uploadDirectory($this->importer))
        ->toBe("imports/{$this->tenant->getKey()}/{$this->importer->getKey()}")
        ->and($this->factory->uploadDirectory($this->stranger))
        ->toBe("imports/{$this->tenant->getKey()}/{$this->stranger->getKey()}");
});

it('names the server disk itself and never takes one from the caller', function (): void {
    expect($this->factory->uploadDisk())->toBe('local');
});

it('refuses a path outside the own upload directory or without a file before it reads anything', function (string $holder, string $pathSuffix, ?string $storedAt): void {
    $path = $this->factory->uploadDirectory($this->{$holder}).$pathSuffix;

    if ($storedAt !== null) {
        ($this->put)($storedAt === 'tenant root' ? "imports/{$this->tenant->getKey()}/secrets.csv" : $path, "name\nAcme\n");
    }

    expect(fn (): CsvReader => $this->factory->make('local', $path, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('local', $path))->toBeNull();
})->with([
    'upload directory of another user' => ['stranger', '/sheet.csv', 'requested path'],
    'climbs out of the upload directory' => ['importer', '/../secrets.csv', 'tenant root'],
    'hides in a subdirectory of the upload directory' => ['importer', '/nested/sheet.csv', 'requested path'],
    'own path without a file' => ['importer', '/gone.csv', null],
]);

it('refuses a disk the caller named instead of the disk the server uses', function (): void {
    Storage::fake('public');
    $path = $this->factory->uploadDirectory($this->importer).'/sheet.csv';
    Storage::disk('public')->put($path, "name\nAcme\n");

    expect(fn (): CsvReader => $this->factory->make('public', $path, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('public', $path))->toBeNull();
});

it('reads the size and row limits from the tenant settings of the caller once the path is their own', function (): void {
    $own = $this->factory->uploadDirectory($this->importer).'/sheet.csv';
    ($this->put)($own, "name\nAcme\n");

    $shape = ($this->attempt)('local', $own);

    expect($shape)->not->toBeNull()
        ->and($shape->targets('tenant_settings'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('reads an own upload from a disk that offers no local path and leaves no temporary copy behind', function (): void {
    $path = $this->factory->uploadDirectory($this->importer).'/sheet.csv';
    ($this->put)($path, "name;city\nAcme;Karlsruhe\nGlobex;Berlin\n");
    ($this->withoutLocalPaths)();
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "tenant_settings"')
        ? [['id' => ModelStub::ulid('reader-settings'), 'tenant_id' => (string) $this->tenant->getKey(), 'import_max_file_bytes' => 2000, 'import_max_rows' => 100]]
        : []);
    $temporaryBefore = glob(sys_get_temp_dir().'/import_*') ?: [];

    $reader = $this->factory->make('local', $path, 'csv', null, $this->importer);

    expect($reader->header())->toBe(['name', 'city'])
        ->and(iterator_to_array($reader->rows(), false))->toBe([
            ['name' => 'Acme', 'city' => 'Karlsruhe'],
            ['name' => 'Globex', 'city' => 'Berlin'],
        ])
        ->and(glob(sys_get_temp_dir().'/import_*') ?: [])->toBe($temporaryBefore);

    StaticQueryConnection::uninstall();
});

it('leaves no temporary copy behind when the stored upload cannot be streamed', function (): void {
    $path = $this->factory->uploadDirectory($this->importer).'/sheet.csv';
    ($this->put)($path, "name\nAcme\n");
    $fake = Storage::disk('local');
    Storage::set('local', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
    {
        public function readStream($path)
        {
            return null;
        }
    });
    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "tenant_settings"')
        ? [['id' => ModelStub::ulid('reader-settings'), 'tenant_id' => (string) $this->tenant->getKey(), 'import_max_file_bytes' => 2000, 'import_max_rows' => 100]]
        : []);
    $temporaryBefore = glob(sys_get_temp_dir().'/import_*') ?: [];

    try {
        expect(fn () => $this->factory->make('local', $path, 'csv', null, $this->importer))->toThrow(RuntimeException::class)
            ->and(glob(sys_get_temp_dir().'/import_*') ?: [])->toBe($temporaryBefore);
    } finally {
        StaticQueryConnection::uninstall();
    }
});
