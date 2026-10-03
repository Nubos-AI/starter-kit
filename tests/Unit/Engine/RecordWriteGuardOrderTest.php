<?php

declare(strict_types=1);

use App\Actions\Engine\UpdateRecordAction;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\ComputedFieldWriter;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordValidator;
use App\Support\Modules\RecordExtensions;
use App\Support\Watchers\WatcherAutoSubscriber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
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
        'version' => 3,
    ], ['objectType' => $this->objectType]);

    AccessContext::actAs(AccessContext::user($this->tenant));

    $this->recordValidator = Mockery::mock(RecordValidator::class);

    $registry = Mockery::mock(ObjectTypeRegistry::class);
    $registry->shouldReceive('forRecord')->andReturn($this->objectType);

    $this->action = new UpdateRecordAction(
        $registry,
        $this->recordValidator,
        Mockery::mock(AuditRecorder::class),
        Mockery::mock(WatcherAutoSubscriber::class),
        Mockery::mock(ComputedFieldWriter::class),
        Mockery::mock(RecordExtensions::class),
    );

    /** @var callable(list<string>):void */
    $this->forbidWriting = function (array $keys): void {
        $resolver = Mockery::mock(FieldVisibilityResolver::class);
        $resolver->shouldReceive('forbiddenWriteFieldKeys')->andReturn($keys);

        app()->instance(FieldVisibilityResolver::class, $resolver);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    app()->forgetInstance(FieldVisibilityResolver::class);
});

it('refuses a write to a field the actor may not write and never validates its value', function (): void {
    ($this->forbidWriting)(['code']);
    $this->recordValidator->shouldNotReceive('validate');

    expect(fn (): CustomRecord => $this->action->execute($this->record, [
        'version' => 3,
        'data' => ['code' => 'GEHEIM-1'],
    ]))->toThrow(AuthorizationException::class);
});

it('names only the forbidden keys the caller actually sent', function (): void {
    ($this->forbidWriting)(['code', 'salary']);
    $this->recordValidator->shouldNotReceive('validate');

    try {
        $this->action->execute($this->record, ['version' => 3, 'data' => ['code' => 'x', 'city' => 'y']]);
    } catch (AuthorizationException $exception) {
        expect($exception->getMessage())->toContain('code')
            ->and($exception->getMessage())->not->toContain('salary')
            ->and($exception->getMessage())->not->toContain('city');
    }
});

it('reaches the value validation only once every key is writable', function (): void {
    ($this->forbidWriting)([]);

    $this->recordValidator->shouldReceive('validate')
        ->once()
        ->andThrow(ValidationException::withMessages(['code' => 'schon vergeben']));

    expect(fn (): CustomRecord => $this->action->execute($this->record, [
        'version' => 3,
        'data' => ['code' => 'GEHEIM-1'],
    ]))->toThrow(ValidationException::class);
});

it('merges the submitted keys onto the stored data before it validates them', function (): void {
    ($this->forbidWriting)([]);

    $received = null;

    $this->recordValidator->shouldReceive('validate')
        ->once()
        ->andReturnUsing(function (ObjectType $type, array $data) use (&$received): never {
            $received = $data;

            throw ValidationException::withMessages(['code' => 'schon vergeben']);
        });

    $record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('company-record'),
        'tenant_id' => $this->tenant->getKey(),
        'object_type_id' => $this->objectType->getKey(),
        'version' => 3,
        'data' => ['code' => 'OFFEN-1', 'city' => 'Karlsruhe'],
    ], ['objectType' => $this->objectType]);

    expect(fn (): CustomRecord => $this->action->execute($record, [
        'version' => 3,
        'data' => ['code' => 'GEHEIM-1'],
    ]))->toThrow(ValidationException::class)
        ->and($received)->toBe(['code' => 'GEHEIM-1', 'city' => 'Karlsruhe']);
});
