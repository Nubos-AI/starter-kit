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

it('stores a file on the disk that configuration names', function (): void {
    $stored = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory);

    expect($stored['disk'])->toBe('local');

    Storage::disk('local')->assertExists($stored['path']);
});

it('follows a changed default disk without any code change', function (): void {
    config(['filesystems.default' => 's3']);

    $stored = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory);

    expect($stored['disk'])->toBe('s3');

    Storage::disk('s3')->assertExists($stored['path']);
    Storage::disk('local')->assertMissing($stored['path']);
});

it('lets an explicitly requested disk override the configured default', function (): void {
    $stored = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory, 's3');

    expect($stored['disk'])->toBe('s3');

    Storage::disk('s3')->assertExists($stored['path']);
});

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

it('hands the stored bytes back both whole and as a stream', function (): void {
    $stored = $this->storage->store(UploadedFile::fake()->createWithContent('note.txt', 'geheim'), $this->directory);

    $stream = $this->storage->readStream($stored['disk'], $stored['path']);

    expect($this->storage->get($stored['disk'], $stored['path']))->toBe('geheim')
        ->and($stream)->toBeResource();

    fclose($stream);
});

it('reports a missing file as nothing instead of throwing', function (): void {
    expect($this->storage->get('local', 'attachments/nothing/here.pdf'))->toBeNull()
        ->and($this->storage->readStream('local', 'attachments/nothing/here.pdf'))->toBeNull();
});

it('removes the bytes through the same abstraction it stored them with', function (): void {
    $stored = $this->storage->store(UploadedFile::fake()->create('report.pdf', 4), $this->directory);

    expect($this->storage->delete($stored['disk'], $stored['path']))->toBeTrue();

    Storage::disk('local')->assertMissing($stored['path']);
});
