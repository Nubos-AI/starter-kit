<?php

declare(strict_types=1);

use App\Support\Storage\AttachmentStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('s3');
    config(['filesystems.default' => 'local']);

    $this->storage = app(AttachmentStorage::class);
    $this->directory = 'attachments/tenant/record';
});

it('stores a file on the configured default disk unless a disk is requested explicitly', function (string $defaultDisk, ?string $requestedDisk, string $expectedDisk, string $untouchedDisk): void {
    config(['filesystems.default' => $defaultDisk]);

    $stored = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory, $requestedDisk);

    expect($stored['disk'])->toBe($expectedDisk);

    Storage::disk($expectedDisk)->assertExists($stored['path']);
    Storage::disk($untouchedDisk)->assertMissing($stored['path']);
})->with([
    'the disk that configuration names' => ['local', null, 'local', 's3'],
    'a changed default disk without any code change' => ['s3', null, 's3', 'local'],
    'an explicitly requested disk over the configured default' => ['local', 's3', 's3', 'local'],
]);

it('never lets the client supplied name reach the disk', function (): void {
    $stored = $this->storage->store(
        UploadedFile::fake()->create('../../../etc/passwd.pdf', 4),
        $this->directory,
    );

    expect($stored['path'])->toStartWith($this->directory.'/')
        ->and($stored['path'])->not->toContain('..')
        ->and($stored['path'])->not->toContain('passwd')
        ->and(Str::isUlid(basename($stored['path'], '.pdf')))->toBeTrue();
});

it('drops an extension that is not a short alphanumeric suffix', function (): void {
    $stored = $this->storage->store(
        UploadedFile::fake()->create('payload.php%00.pdf', 4),
        $this->directory,
    );

    expect(pathinfo($stored['path'], PATHINFO_EXTENSION))->toBe('pdf')
        ->and($stored['path'])->not->toContain('php');
});

it('gives two uploads of the same name two distinct paths', function (): void {
    $first = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory);
    $second = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory);

    expect($first['path'])->not->toBe($second['path']);

    Storage::disk('local')->assertExists($first['path']);
    Storage::disk('local')->assertExists($second['path']);
});

it('reports a missing file as nothing instead of throwing', function (): void {
    expect($this->storage->get('local', 'attachments/nothing/here.pdf'))->toBeNull()
        ->and($this->storage->readStream('local', 'attachments/nothing/here.pdf'))->toBeNull();
});
