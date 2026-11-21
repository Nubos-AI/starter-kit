<?php

declare(strict_types=1);

use App\Actions\Engine\RollbackDefinitionAction;
use App\Models\ObjectType;
use App\Models\ObjectTypeDefinitionVersion;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('object-type'),
        'tenant_id' => $this->tenant->getKey(),
        'key' => 'deal',
    ]);

    $this->versionOf = fn (string $objectTypeId): ObjectTypeDefinitionVersion => ModelStub::make(ObjectTypeDefinitionVersion::class, [
        'id' => ModelStub::ulid('version'),
        'object_type_id' => $objectTypeId,
        'version_number' => 1,
        'snapshot' => ['object_type' => ['key' => 'deal'], 'field_definitions' => []],
    ]);

    $this->restorableKeys = static function (string $configKey): array {
        $keys = config('engine.definition_snapshot.'.$configKey);

        expect($keys)->toBeArray();

        /** @var list<string> $keys */
        return array_values($keys);
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses a rollback to a version of another object type before touching the database', function (): void {
    $foreignVersion = ($this->versionOf)(ModelStub::ulid('other-object-type'));

    $caught = null;

    $shape = QueryShape::attemptedBy(function () use ($foreignVersion, &$caught): void {
        try {
            app(RollbackDefinitionAction::class)->execute($this->objectType, $foreignVersion);
        } catch (ValidationException $exception) {
            $caught = $exception;
        }
    });

    expect($caught)->toBeInstanceOf(ValidationException::class)
        ->and($caught?->errors())->toHaveKey('version')
        ->and($shape)->toBeNull();
});

it('names the automatic snapshot taken before a rollback in german', function (): void {
    expect(__('i18n.backend.actions.engine.rollback_definition_action.automatic_snapshot_before_rollback'))
        ->toBe('Automatischer Snapshot vor dem Zurücksetzen');
});

it('keeps identity and tenancy out of the restorable object type keys', function (): void {
    $restorable = ($this->restorableKeys)('object_type_restorable');

    expect($restorable)->not->toContain('id')
        ->and($restorable)->not->toContain('tenant_id')
        ->and($restorable)->not->toBe([]);
});
