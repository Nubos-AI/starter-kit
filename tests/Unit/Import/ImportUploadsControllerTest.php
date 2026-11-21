<?php

declare(strict_types=1);

use App\Http\Controllers\Import\ImportUploadsController;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    Config::set('filesystems.default', 'local');
    Storage::fake('local');

    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();
    $this->importer = AccessContext::actAs(AccessContext::user($this->tenant, [], 'upload-importer'));

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('upload-companies'),
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
    ]);

    StaticQueryConnection::install(fn (string $sql): array => str_contains($sql, 'from "tenant_settings"')
        ? [['id' => ModelStub::ulid('upload-settings'), 'tenant_id' => $this->tenantId, 'import_max_file_bytes' => 2000, 'import_max_rows' => 100]]
        : []);

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

    /** @var callable(UploadedFile):JsonResponse */
    $this->upload = function (UploadedFile $file): JsonResponse {
        $request = Request::create('/probe', 'POST', [], [], ['file' => $file]);
        $request->setUserResolver(fn (): User => $this->importer);

        return app(ImportUploadsController::class)->store($request, $this->objectType);
    };

    /** @var callable(UploadedFile):array<string, list<string>> */
    $this->refusal = function (UploadedFile $file): array {
        try {
            ($this->upload)($file);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return [];
    };
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
});

it('refuses a file above the tenant byte limit before it stores or parses anything', function (): void {
    $errors = ($this->refusal)(UploadedFile::fake()->createWithContent('companies.xlsx', str_repeat('x', 4096)));

    expect($errors)->toHaveKey('file')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a file one byte above the tenant limit although it rounds to the same kilobyte', function (): void {
    $errors = ($this->refusal)(UploadedFile::fake()->createWithContent('companies.csv', str_repeat('x', 2001)));

    expect($errors)->toHaveKey('file')
        ->and($errors['file'][0])->toContain('2000')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a spreadsheet it cannot read, logs why and keeps no copy of it', function (): void {
    Log::spy();

    $errors = ($this->refusal)(UploadedFile::fake()->createWithContent('companies.xlsx', str_repeat("\x00\xff", 200)));

    expect($errors)->toHaveKey('file')
        ->and($errors['file'][0])->toContain('nicht gelesen')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['tenant_id'] === $this->tenantId
            && $context['user_id'] === (string) $this->importer->getKey()
            && $context['format'] === 'xlsx'
            && is_string($context['exception']));
});

it('stores a readable csv within the limit and suggests its header', function (): void {
    $response = ($this->upload)(UploadedFile::fake()->createWithContent('companies.csv', "name;city\nAcme;Karlsruhe\n"));

    expect($response->getData(true)['format'])->toBe('csv')
        ->and($response->getData(true)['headerSuggestion'])->toBe(['name', 'city'])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('stores and reads an upload on a disk that offers no local path', function (): void {
    ($this->withoutLocalPaths)();

    $response = ($this->upload)(UploadedFile::fake()->createWithContent('companies.csv', "name;city\nAcme;Karlsruhe\n"));

    expect($response->getData(true)['headerSuggestion'])->toBe(['name', 'city'])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});
