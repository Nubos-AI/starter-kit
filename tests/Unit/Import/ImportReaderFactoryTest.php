<?php

declare(strict_types=1);

use App\Support\Import\CsvReader;
use App\Support\Import\FileFormatDetector;
use App\Support\Import\ImportReaderFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
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

it('refuses a path that points into the upload directory of another user', function (): void {
    $foreign = $this->factory->uploadDirectory($this->stranger).'/sheet.csv';
    ($this->put)($foreign, "name\nAcme\n");

    expect(fn (): CsvReader => $this->factory->make('local', $foreign, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('local', $foreign))->toBeNull();
});

it('refuses a path that climbs out of the upload directory', function (): void {
    $escape = $this->factory->uploadDirectory($this->importer).'/../secrets.csv';
    ($this->put)("imports/{$this->tenant->getKey()}/secrets.csv", "name\nAcme\n");

    expect(fn (): CsvReader => $this->factory->make('local', $escape, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('local', $escape))->toBeNull();
});

it('refuses a path that hides in a subdirectory of the upload directory', function (): void {
    $nested = $this->factory->uploadDirectory($this->importer).'/nested/sheet.csv';
    ($this->put)($nested, "name\nAcme\n");

    expect(fn (): CsvReader => $this->factory->make('local', $nested, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('local', $nested))->toBeNull();
});

it('refuses a disk the caller named instead of the disk the server uses', function (): void {
    Storage::fake('public');
    $path = $this->factory->uploadDirectory($this->importer).'/sheet.csv';
    Storage::disk('public')->put($path, "name\nAcme\n");

    expect(fn (): CsvReader => $this->factory->make('public', $path, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('public', $path))->toBeNull();
});

it('refuses an own path for which no file exists', function (): void {
    $missing = $this->factory->uploadDirectory($this->importer).'/gone.csv';

    expect(fn (): CsvReader => $this->factory->make('local', $missing, 'csv', null, $this->importer))
        ->toThrow(ValidationException::class)
        ->and(($this->attempt)('local', $missing))->toBeNull();
});

it('reads the size and row limits from the tenant settings of the caller once the path is their own', function (): void {
    $own = $this->factory->uploadDirectory($this->importer).'/sheet.csv';
    ($this->put)($own, "name\nAcme\n");

    $shape = ($this->attempt)('local', $own);

    expect($shape)->not->toBeNull()
        ->and($shape->targets('tenant_settings'))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});
