<?php

declare(strict_types=1);

use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    AccessContext::suspendRowAccess();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
    ], ['objectType' => $this->objectType]);

    $this->action = app(SyncRecordCollaboratorsAction::class);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a collaborator payload that is not a list of identifiers', function (array $payload, string $errorKey): void {
    expect(function () use ($payload): void {
        $this->action->execute($this->record, $payload);
    })->toThrow(function (ValidationException $exception) use ($errorKey): void {
        expect($exception->errors())->toHaveKey($errorKey);
    });
})->with([
    'no list at all' => [[], 'collaborator_ids'],
    'no list of users' => [['collaborator_ids' => 'alle'], 'collaborator_ids'],
    'entry that is no identifier' => [['collaborator_ids' => ['nicht-ulid']], 'collaborator_ids.0'],
]);
