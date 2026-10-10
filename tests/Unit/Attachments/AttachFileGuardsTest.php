<?php

declare(strict_types=1);

use App\Actions\Engine\AttachFileAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.default' => 'local']);

    $this->tenant = AccessContext::tenant();
    $this->action = app(AttachFileAction::class);
    $this->recordId = ModelStub::ulid('attachment-record');

    /** @var callable(array<string, mixed>):array<string, list<string>> */
    $this->refusalOf = function (array $input): array {
        try {
            $this->action->execute($input);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('the action accepted an upload it should have refused');
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('looks the target record up inside the bound tenant only', function (): void {
    $shape = QueryShape::attemptedBy(fn (): mixed => $this->action->execute([
        'record_id' => $this->recordId,
        'file' => UploadedFile::fake()->create('report.pdf', 4),
    ]));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('custom_records'))->toBeTrue()
        ->and($shape->hasBinding($this->recordId))->toBeTrue()
        ->and($shape->sql)->toContain('"tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeTrue();
});

it('refuses an upload that names no record, and stores nothing on the way out', function (): void {
    expect(($this->refusalOf)(['file' => UploadedFile::fake()->create('report.pdf', 4)]))->toHaveKey('record_id')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a record identifier that is not a string before it ever reaches the lookup', function (): void {
    expect(($this->refusalOf)([
        'record_id' => ['not', 'a', 'string'],
        'file' => UploadedFile::fake()->create('report.pdf', 4),
    ]))->toHaveKey('record_id');
});

it('refuses a missing file and anything in the file slot that is not an uploaded file', function (): void {
    $withoutFile = ($this->refusalOf)(['record_id' => ['not', 'a', 'string']]);
    $withAString = ($this->refusalOf)(['record_id' => ['not', 'a', 'string'], 'file' => 'just a string']);

    expect($withoutFile)->toHaveKey('file')
        ->and($withAString)->toHaveKey('file')
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('stores nothing at all while the target record cannot be confirmed', function (): void {
    QueryShape::attemptedBy(fn (): mixed => $this->action->execute([
        'record_id' => $this->recordId,
        'file' => UploadedFile::fake()->create('report.pdf', 4),
    ]));

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
