<?php

declare(strict_types=1);

use App\Actions\Engine\UpdateRecordAction;
use App\Exceptions\StaleRecordException;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('companies'),
        'tenant_id' => $this->tenant->getKey(),
        'slug' => 'companies',
    ]);

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'record_number' => 'CO-0000000001',
        'version' => 7,
    ], ['objectType' => $this->objectType]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses an update that carries no version at all', function (): void {
    expect(fn (): CustomRecord => app(UpdateRecordAction::class)
        ->execute($this->record, ['external_reference_id' => 'ext-1']))
        ->toThrow(ValidationException::class);
});

it('refuses an update whose version is not an integer', function (): void {
    expect(fn (): CustomRecord => app(UpdateRecordAction::class)
        ->execute($this->record, ['version' => 'seven']))
        ->toThrow(ValidationException::class);
});

it('takes a well formed version through validation and on to the conditional statement', function (): void {
    expect(fn (): CustomRecord => app(UpdateRecordAction::class)
        ->execute($this->record, ['version' => 7, 'external_reference_id' => 'ext-1']))
        ->toThrow(PDOException::class);
});

it('answers a version conflict with a conflict status instead of a server error', function (): void {
    $response = (new StaleRecordException)->render(Request::create('/probe', 'PUT'));

    expect($response->getStatusCode())->toBe(Response::HTTP_CONFLICT)
        ->and($response->getData(true))->toHaveKey('message')
        ->and($response->getData(true))->not->toHaveKey('data');
});

it('hands the caller the version it lost against so the form can reload', function (): void {
    expect((new StaleRecordException)->withRecord($this->record)->currentVersion())->toBe(7);
});

it('reports no version when no record was attached to the conflict', function (): void {
    expect((new StaleRecordException)->currentVersion())->toBeNull();
});
