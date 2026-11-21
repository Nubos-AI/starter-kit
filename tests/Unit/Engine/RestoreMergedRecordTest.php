<?php

declare(strict_types=1);

use App\Actions\Engine\RestoreRecordAction;
use App\Models\CustomRecord;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\Support\WriteAttempt;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    app()->setLocale('de');
    $this->tenant = AccessContext::tenant();
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to restore a record that was merged into another one and writes nothing', function (): void {
    $source = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('merged-source'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => ModelStub::ulid('companies'),
        'merged_into_record_id' => ModelStub::ulid('merge-target'),
        'deleted_at' => '2026-10-30 10:00:00',
        'version' => 3,
    ]);

    $errors = [];

    $reached = WriteAttempt::reachedTheDatabase(function () use ($source, &$errors): void {
        try {
            app(RestoreRecordAction::class)->execute($source);
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }
    });

    expect($reached)->toBeFalse()
        ->and($errors)->toHaveKey('record')
        ->and($errors['record'][0])->toContain('rückgängig');
});
