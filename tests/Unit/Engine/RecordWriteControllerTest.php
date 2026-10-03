<?php

declare(strict_types=1);

use App\Actions\Engine\CreateRecordAction;
use App\Actions\Engine\DeleteRecordAction;
use App\Actions\Engine\DuplicateRecordAction;
use App\Actions\Engine\UpdateRecordAction;
use App\Actions\Engine\UpdateRecordCellAction;
use App\Actions\Records\SyncRecordCollaboratorsAction;
use App\Http\Controllers\Engine\RecordWriteController;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\GateSpy;
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
        'version' => 3,
    ], ['objectType' => $this->objectType]);

    $this->createRecord = Mockery::mock(CreateRecordAction::class);
    $this->updateRecord = Mockery::mock(UpdateRecordAction::class);
    $this->updateCell = Mockery::mock(UpdateRecordCellAction::class);
    $this->deleteRecord = Mockery::mock(DeleteRecordAction::class);
    $this->duplicateRecord = Mockery::mock(DuplicateRecordAction::class);
    $this->syncCollaborators = Mockery::mock(SyncRecordCollaboratorsAction::class);

    $this->controller = new RecordWriteController(
        $this->createRecord,
        $this->updateRecord,
        $this->updateCell,
        $this->deleteRecord,
        $this->duplicateRecord,
        $this->syncCollaborators,
    );

    /** @var callable(array<string, mixed>):Request */
    $this->requestWith = function (array $payload): Request {
        $request = Request::create('/probe', 'PUT', $payload);
        $user = AccessContext::user($this->tenant);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses an update the gate denies and never reaches the action', function (): void {
    $gate = GateSpy::allowing();
    $this->updateRecord->shouldNotReceive('execute');

    $request = ($this->requestWith)(['data' => ['name' => 'Neu']]);

    expect(fn (): JsonResponse => $this->controller->update($request, $this->record))
        ->toThrow(AuthorizationException::class)
        ->and($gate->abilities())->toBe(['update'])
        ->and($gate->calls[0]['arguments'])->toBe([$this->record]);
});

it('hands the update action only the writable keys', function (): void {
    GateSpy::allowing('update');

    $received = null;

    $this->updateRecord->shouldReceive('execute')
        ->once()
        ->andReturnUsing(function (CustomRecord $record, array $attributes) use (&$received): never {
            $received = $attributes;

            throw ValidationException::withMessages(['data' => 'ungueltig']);
        });

    $response = $this->controller->update(
        ($this->requestWith)([
            'data' => ['name' => 'Neu'],
            'owner_id' => ModelStub::ulid('owner'),
            'external_reference_id' => 'ext-1',
            'version' => 3,
            'object_type_id' => ModelStub::ulid('contacts'),
            'tenant_id' => ModelStub::ulid('other-tenant'),
        ]),
        $this->record,
    );

    expect(array_keys((array) $received))->toBe(['data', 'owner_id', 'external_reference_id', 'version'])
        ->and($response->getStatusCode())->toBe(422);
});

it('does not sync collaborators when the payload carries none', function (): void {
    GateSpy::allowing('update');

    $this->syncCollaborators->shouldNotReceive('execute');
    $this->updateRecord->shouldReceive('execute')
        ->once()
        ->andThrow(ValidationException::withMessages(['data' => 'ungueltig']));

    $response = $this->controller->update(($this->requestWith)(['data' => []]), $this->record);

    expect($response->getStatusCode())->toBe(422);
});

it('refuses to create a record without the object type create permission', function (): void {
    $resolver = AccessContext::grant('companies.view');
    $this->createRecord->shouldNotReceive('execute');

    $request = ($this->requestWith)(['data' => []]);

    expect(fn (): JsonResponse => $this->controller->store($request, $this->objectType))
        ->toThrow(AuthorizationException::class)
        ->and($resolver->askedFor)->toBe(['companies.create']);
});

it('refuses to create a record for an unauthenticated caller', function (): void {
    AccessContext::grant('companies.create');
    $this->createRecord->shouldNotReceive('execute');

    $request = Request::create('/probe', 'POST', ['data' => []]);
    $request->setUserResolver(static fn (): ?User => null);

    expect(fn (): JsonResponse => $this->controller->store($request, $this->objectType))
        ->toThrow(AuthorizationException::class);
});
