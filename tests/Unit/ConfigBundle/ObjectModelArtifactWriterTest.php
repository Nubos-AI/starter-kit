<?php

declare(strict_types=1);

use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Actions\Engine\CreateFieldGroupAction;
use App\Actions\Engine\CreateMergeRuleAction;
use App\Actions\Engine\CreateObjectTypeAction;
use App\Actions\Engine\CreateRelationshipTypeAction;
use App\Actions\Engine\CreateReminderTypeAction;
use App\Actions\Engine\DeleteFieldDefinitionAction;
use App\Actions\Engine\DeleteFieldGroupAction;
use App\Actions\Engine\DeleteMergeRuleAction;
use App\Actions\Engine\DeleteObjectTypeAction;
use App\Actions\Engine\DeleteRelationshipTypeAction;
use App\Actions\Engine\DeleteReminderTypeAction;
use App\Actions\Engine\PurgeObjectTypeAction;
use App\Actions\Engine\UpdateFieldDefinitionAction;
use App\Actions\Engine\UpdateFieldGroupAction;
use App\Actions\Engine\UpdateMergeRuleAction;
use App\Actions\Engine\UpdateObjectTypeAction;
use App\Actions\Engine\UpdateRelationshipTypeAction;
use App\Actions\Engine\UpdateReminderTypeAction;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Engine\StorageStrategy;
use App\Enums\Promotion\DiffState;
use App\Handlers\ConfigBundle\ObjectModelArtifactWriter;
use App\Models\ObjectType;
use App\Models\RelationshipType;
use App\Models\ReminderType;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\SystemObjectTypeGuard;
use App\Support\Tenancy\ActingUserContext;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\RoleHolder;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->actor = RoleHolder::make(['tenant_id' => $this->tenantId], [], 'object-model-actor');

    $this->ids = new ArrayObject;
    $this->parents = new ArrayObject;
    $this->prefixes = new ArrayObject;
    $this->invalidated = new ArrayObject;
    $this->actingRuns = new ArrayObject;

    $targetKeys = Mockery::mock(TargetKeyResolver::class);

    $targetKeys->allows('idFor')->andReturnUsing(function (ArtifactKind|string $kind, string $businessKey): ?string {
        $table = $kind instanceof ArtifactKind ? $kind->value : $kind;

        return $this->ids["{$table}|{$businessKey}"] ?? null;
    });

    $targetKeys->allows('parentIdFor')->andReturnUsing(fn (ArtifactKind $kind, string $businessKey, string $column): ?string => $this->parents["{$kind->value}|{$businessKey}|{$column}"] ?? null);

    $targetKeys->allows('knownPrefixOf')->andReturnUsing(fn (ArtifactKind $parent, string $businessKey): ?string => $this->prefixes["{$parent->value}|{$businessKey}"] ?? null);

    $targetKeys->allows('invalidate')->andReturnUsing(function (ArtifactKind $kind): void {
        $this->invalidated[] = $kind->value;
    });

    $actingUserContext = Mockery::mock(ActingUserContext::class);

    $actingUserContext->allows('run')->andReturnUsing(function (string $tenantId, string $actingUserId, Closure $callback): void {
        $this->actingRuns[] = ['tenantId' => $tenantId, 'actingUserId' => $actingUserId];

        $callback($this->actor);
    });

    $this->createObjectType = Mockery::mock(CreateObjectTypeAction::class);
    $this->createRelationshipType = Mockery::mock(CreateRelationshipTypeAction::class);
    $this->createReminderType = Mockery::mock(CreateReminderTypeAction::class);
    $this->purgeObjectType = Mockery::spy(PurgeObjectTypeAction::class);
    $this->updateObjectType = Mockery::mock(UpdateObjectTypeAction::class);

    $this->writer = new ObjectModelArtifactWriter(
        $actingUserContext,
        Mockery::mock(CreateFieldDefinitionAction::class),
        Mockery::mock(CreateFieldGroupAction::class),
        Mockery::mock(CreateMergeRuleAction::class),
        $this->createObjectType,
        $this->createRelationshipType,
        $this->createReminderType,
        Mockery::mock(DeleteFieldDefinitionAction::class),
        Mockery::mock(DeleteFieldGroupAction::class),
        Mockery::mock(DeleteMergeRuleAction::class),
        Mockery::mock(DeleteObjectTypeAction::class),
        Mockery::mock(DeleteRelationshipTypeAction::class),
        Mockery::mock(DeleteReminderTypeAction::class),
        Mockery::mock(FilterFieldKeyCollector::class),
        $this->purgeObjectType,
        new SystemObjectTypeGuard,
        $targetKeys,
        Mockery::mock(UpdateFieldDefinitionAction::class),
        Mockery::mock(UpdateFieldGroupAction::class),
        Mockery::mock(UpdateMergeRuleAction::class),
        $this->updateObjectType,
        Mockery::mock(UpdateRelationshipTypeAction::class),
        Mockery::mock(UpdateReminderTypeAction::class),
    );

    /** @var callable(ArtifactKind, string, array<string, mixed>, DiffState):ArtifactWriteResult */
    $this->apply = fn (ArtifactKind $kind, string $key, array $payload = [], DiffState $state = DiffState::Added): ArtifactWriteResult => $this->writer->apply(
        $this->actor,
        $kind,
        new BundleArtifact($kind, $key, $payload),
        $state,
    );

    /** @var callable(ArtifactWriteResult):string */
    $this->reasonOf = fn (ArtifactWriteResult $result): string => implode(' ', $result->notes);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('carries exactly the artifact kinds of the object model group', function (): void {
    $carried = array_values(array_filter(
        ArtifactKind::cases(),
        fn (ArtifactKind $kind): bool => $this->writer->supports($kind),
    ));

    expect($carried)->toBe([
        ArtifactKind::ObjectTypes,
        ArtifactKind::FieldGroups,
        ArtifactKind::FieldDefinitions,
        ArtifactKind::RelationshipTypes,
        ArtifactKind::FieldDependencies,
        ArtifactKind::MergeRules,
        ArtifactKind::ReminderTypes,
    ]);
});

it('refuses to write at all while no target tenant is bound instead of reporting a silent skip', function (): void {
    AccessContext::forgetTenant();

    expect(fn (): ArtifactWriteResult => ($this->apply)(ArtifactKind::ReminderTypes, 'Rückruf'))
        ->toThrow(AuthorizationException::class);

    expect($this->actingRuns->getArrayCopy())->toBe([]);
});

it('hands the bound tenant and the acting user to the acting context of every write', function (): void {
    $this->createReminderType->expects('execute')->andReturn(ModelStub::make(ReminderType::class));

    ($this->apply)(ArtifactKind::ReminderTypes, 'Rückruf');

    expect($this->actingRuns->getArrayCopy())->toBe([[
        'tenantId' => $this->tenantId,
        'actingUserId' => (string) $this->actor->getKey(),
    ]]);
});

it('skips a diff state that asks for no write and names the state in the reason', function (DiffState $state): void {
    $result = ($this->apply)(ArtifactKind::ReminderTypes, 'Rückruf', [], $state);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain($state->value)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'unchanged' => DiffState::Unchanged,
    'removed' => DiffState::Removed,
    'conflicted' => DiffState::Conflicted,
]);

it('skips an applied field dependency because the edge follows from its field', function (): void {
    $result = ($this->apply)(ArtifactKind::FieldDependencies, 'deal:amount:stage');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->notes)->not->toBe([])
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('skips a removed field dependency instead of deleting an edge behind the field', function (): void {
    $result = $this->writer->remove($this->actor, ArtifactKind::FieldDependencies, 'deal:amount:stage');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->notes)->not->toBe([]);
});

it('skips an object type payload that declares itself a system object type', function (): void {
    $result = ($this->apply)(ArtifactKind::ObjectTypes, 'deal', ['is_system' => true, 'name' => 'Geschäft']);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->notes)->not->toBe([])
        ->and($this->invalidated->getArrayCopy())->toBe([]);
});

it('announces the refusal of a system object type before the run reaches the write', function (): void {
    $refusal = $this->writer->refusalFor(
        ArtifactKind::ObjectTypes,
        new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ['is_system' => true]),
        DiffState::Added,
    );

    expect($refusal)->not->toBeNull()
        ->and($refusal?->reason)->not->toBe('');
});

it('announces no refusal for an artifact kind that is not an object type', function (): void {
    $refusal = $this->writer->refusalFor(
        ArtifactKind::FieldDefinitions,
        new BundleArtifact(ArtifactKind::FieldDefinitions, 'deal:amount', ['is_system' => true]),
        DiffState::Added,
    );

    expect($refusal)->toBeNull();
});

it('refuses a modified object type that the target does not carry and names the key', function (): void {
    $result = ($this->apply)(ArtifactKind::ObjectTypes, 'deal', ['name' => 'Geschäft'], DiffState::Modified);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('deal');
});

it('creates an object type from its business key and names the missing slug in a note', function (): void {
    $objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('created-object-type'),
        'tenant_id' => $this->tenantId,
        'key' => 'deal',
        'slug' => 'deals',
        'business_key_prefix' => null,
        'record_number_format' => null,
    ]);

    $this->createObjectType->expects('execute')
        ->withArgs(function (array $input, bool $isSystem, StorageStrategy $storageStrategy, ?string $slug, bool $defaultsRecordNumberFormat): bool {
            expect($input)->toBe([
                'key' => 'deal',
                'name' => 'Geschäft',
                'business_key_prefix' => 'DEA',
                'record_number_format' => null,
            ])
                ->and($isSystem)->toBeFalse()
                ->and($storageStrategy)->toBe(StorageStrategy::Native)
                ->and($slug)->toBeNull()
                ->and($defaultsRecordNumberFormat)->toBeFalse();

            return true;
        })
        ->andReturn($objectType);

    $this->updateObjectType->expects('execute')
        ->withArgs(function (ObjectType $target, array $input, ?RelationshipType $hierarchyCarrier): bool {
            expect($input['hierarchy_enabled'])->toBeFalse()
                ->and($input['business_key_prefix'])->toBe('DEA')
                ->and($input['record_number_format'])->toBeNull()
                ->and($input['nav_position'])->toBe(7)
                ->and($hierarchyCarrier)->toBeNull();

            return true;
        })
        ->andReturn($objectType);

    $result = ($this->apply)(ArtifactKind::ObjectTypes, 'deal', [
        'name' => 'Geschäft',
        'business_key_prefix' => 'DEA',
        'record_number_format' => null,
        'storage_strategy' => StorageStrategy::Native->value,
        'nav_position' => 7,
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $objectType->getKey())
        ->and($result->notes)->not->toBe([])
        ->and($this->invalidated->getArrayCopy())->toBe([ArtifactKind::ObjectTypes->value]);
});

it('marks an object type whose hierarchy carrier is not written yet as awaiting that reference', function (): void {
    $objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('created-object-type'),
        'key' => 'deal',
    ]);

    $this->createObjectType->expects('execute')->andReturn($objectType);

    $this->updateObjectType->expects('execute')
        ->withArgs(function (ObjectType $target, array $input, ?RelationshipType $hierarchyCarrier): bool {
            expect($input)->not->toHaveKey('hierarchy_enabled')
                ->and($hierarchyCarrier)->toBeNull();

            return true;
        })
        ->andReturn($objectType);

    $result = ($this->apply)(ArtifactKind::ObjectTypes, 'deal', [
        'name' => 'Geschäft',
        'hierarchy_relationship_type_id' => 'deal-untergeschaeft',
    ]);

    expect($result->awaitsReference())->toBeTrue()
        ->and((string) $result->awaitedReference)->toContain('deal-untergeschaeft');
});

it('skips an artifact whose object type is missing in the target and names the reference', function (ArtifactKind $kind, string $key): void {
    $result = ($this->apply)($kind, $key, ['name' => 'Irgendwas']);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain('object_type_id')
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'field groups' => [ArtifactKind::FieldGroups, 'deal:Stammdaten'],
    'field definitions' => [ArtifactKind::FieldDefinitions, 'deal:amount'],
    'merge rules' => [ArtifactKind::MergeRules, 'deal:Dublette'],
]);

it('skips a relationship type whose endpoint is missing in the target and names the endpoint column', function (string $column): void {
    $payload = [
        'name' => 'gehört zu',
        'from_object_type_id' => 'deal',
        'to_object_type_id' => 'company',
    ];

    $this->ids['object-types|'.($column === 'from_object_type_id' ? 'company' : 'deal')] = ModelStub::ulid('known-object-type');

    $result = ($this->apply)(ArtifactKind::RelationshipTypes, 'deal-company', $payload);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and($result->awaitsReference())->toBeTrue()
        ->and(($this->reasonOf)($result))->toContain($column);
})->with(['from_object_type_id', 'to_object_type_id']);

it('translates both endpoints of a relationship type into ulids of the target tenant', function (): void {
    $this->ids['object-types|deal'] = ModelStub::ulid('deal-object-type');
    $this->ids['object-types|company'] = ModelStub::ulid('company-object-type');

    $relationshipType = ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('created-relationship'),
        'key' => 'gehoert-zu',
        'inverse_key' => 'hat',
    ]);

    $this->createRelationshipType->expects('execute')
        ->withArgs(function (array $input): bool {
            expect($input['from_object_type_id'])->toBe(ModelStub::ulid('deal-object-type'))
                ->and($input['to_object_type_id'])->toBe(ModelStub::ulid('company-object-type'))
                ->and($input)->not->toHaveKey('is_hierarchy');

            return true;
        })
        ->andReturn($relationshipType);

    $result = ($this->apply)(ArtifactKind::RelationshipTypes, 'deal-company', [
        'name' => 'gehört zu',
        'inverse_name' => 'hat',
        'from_object_type_id' => 'deal',
        'to_object_type_id' => 'company',
        'is_hierarchy' => true,
        'key' => 'gehoert-zu',
        'inverse_key' => 'hat',
    ]);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $relationshipType->getKey())
        ->and($result->notes)->toHaveCount(1)
        ->and($this->invalidated->getArrayCopy())->toBe([ArtifactKind::RelationshipTypes->value]);
});

it('names the derived keys of a relationship type that differ from the payload', function (): void {
    $this->ids['object-types|deal'] = ModelStub::ulid('deal-object-type');
    $this->ids['object-types|company'] = ModelStub::ulid('company-object-type');

    $this->createRelationshipType->expects('execute')->andReturn(ModelStub::make(RelationshipType::class, [
        'id' => ModelStub::ulid('created-relationship'),
        'key' => 'gehoert-zu',
        'inverse_key' => 'hat',
    ]));

    $result = ($this->apply)(ArtifactKind::RelationshipTypes, 'deal-company', [
        'name' => 'gehört zu',
        'from_object_type_id' => 'deal',
        'to_object_type_id' => 'company',
        'key' => 'aus-dem-bundle',
        'inverse_key' => 'hat',
    ]);

    expect(($this->reasonOf)($result))->toContain('key')
        ->and(($this->reasonOf)($result))->not->toContain('inverse_key');
});

it('reports a modified artifact without a counterpart in the target as a missing target', function (ArtifactKind $kind, string $key, array $payload): void {
    foreach (['deal', 'company'] as $objectTypeKey) {
        $this->ids["object-types|{$objectTypeKey}"] = ModelStub::ulid($objectTypeKey);
    }

    $result = ($this->apply)($kind, $key, $payload, DiffState::Modified);

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain($key)
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'relationship types' => [ArtifactKind::RelationshipTypes, 'deal-company', ['name' => 'gehört zu', 'from_object_type_id' => 'deal', 'to_object_type_id' => 'company']],
    'reminder types' => [ArtifactKind::ReminderTypes, 'Rückruf', []],
]);

it('takes the reminder type name from the business key', function (): void {
    $reminderType = ModelStub::make(ReminderType::class, ['id' => ModelStub::ulid('created-reminder-type')]);

    $this->createReminderType->expects('execute')
        ->withArgs(function (array $input): bool {
            expect($input)->toBe(['name' => 'Rückruf']);

            return true;
        })
        ->andReturn($reminderType);

    $result = ($this->apply)(ArtifactKind::ReminderTypes, 'Rückruf', ['name' => 'Aus der Nutzlast']);

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($result->modelId)->toBe((string) $reminderType->getKey())
        ->and($this->invalidated->getArrayCopy())->toBe([ArtifactKind::ReminderTypes->value]);
});

it('reports a removal whose key names nothing in the target as a missing target', function (ArtifactKind $kind): void {
    $result = $this->writer->remove($this->actor, $kind, 'deal:Unbekannt');

    expect($result->action)->toBe(ArtifactWriteAction::Skipped)
        ->and(($this->reasonOf)($result))->toContain('deal:Unbekannt')
        ->and($this->invalidated->getArrayCopy())->toBe([]);
})->with([
    'object types' => ArtifactKind::ObjectTypes,
    'field groups' => ArtifactKind::FieldGroups,
    'field definitions' => ArtifactKind::FieldDefinitions,
    'relationship types' => ArtifactKind::RelationshipTypes,
    'merge rules' => ArtifactKind::MergeRules,
    'reminder types' => ArtifactKind::ReminderTypes,
]);

it('purges nothing for an overwrite of an artifact kind that is not an object type', function (): void {
    $this->writer->clearForOverwrite(
        ArtifactKind::FieldDefinitions,
        new BundleArtifact(ArtifactKind::FieldDefinitions, 'deal:amount', ['slug' => 'deals']),
    );

    $this->purgeObjectType->shouldNotHaveReceived('execute');
});

it('purges nothing for an overwrite of an object type artifact that names no slug', function (): void {
    $this->writer->clearForOverwrite(
        ArtifactKind::ObjectTypes,
        new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ['name' => 'Geschäft']),
    );

    $this->purgeObjectType->shouldNotHaveReceived('execute');
});

it('purges a trashed object type that still holds the slug only when the bundle maps to no live target', function (?string $deletedAt, ?string $knownTargetId, bool $isPurged): void {
    $holderId = ModelStub::ulid('slug-holder');

    if ($knownTargetId !== null) {
        $this->ids['object-types|deal'] = $knownTargetId;
    }

    $connection = StaticQueryConnection::install(static fn (string $sql): array => str_contains($sql, 'from "object_types"')
        ? [['id' => $holderId, 'key' => 'deal', 'slug' => 'deals', 'name' => 'Geschäft', 'deleted_at' => $deletedAt]]
        : []);

    $purged = [];

    if ($isPurged) {
        $this->purgeObjectType->expects('execute')->andReturnUsing(function (ObjectType $holder) use (&$purged): void {
            $purged[] = (string) $holder->getKey();
        });
    }

    $this->writer->clearForOverwrite(
        ArtifactKind::ObjectTypes,
        new BundleArtifact(ArtifactKind::ObjectTypes, 'deal', ['slug' => 'deals', 'name' => 'Geschäft']),
    );

    StaticQueryConnection::uninstall();

    $lookup = collect($connection->queries)->first(fn (array $query): bool => str_contains($query['sql'], 'from "object_types"'));

    expect($lookup)->not->toBeNull();

    if ($isPurged) {
        expect($purged)->toBe([$holderId]);

        return;
    }

    $this->purgeObjectType->shouldNotHaveReceived('execute');
})->with([
    'a trashed holder the bundle maps to no target' => ['2026-01-05 10:00:00', null, true],
    'a live holder of the slug' => [null, null, false],
    'a trashed holder while the bundle key maps to a live target' => ['2026-01-05 10:00:00', 'live-target-id', false],
]);

it('leaves every write to its actions and opens no transaction of its own', function (): void {
    $objectType = ModelStub::make(ObjectType::class, [
        'id' => ModelStub::ulid('untouched-object-type'),
        'key' => 'deal',
    ]);

    $this->createObjectType->expects('execute')->andReturn($objectType);
    $this->updateObjectType->allows('execute')->andReturn($objectType);

    $connection = StaticQueryConnection::install(static fn (): array => []);

    $result = ($this->apply)(ArtifactKind::ObjectTypes, 'deal', [
        'key' => 'deal',
        'name' => 'Geschäft',
        'business_key_prefix' => 'DEA',
    ]);

    StaticQueryConnection::uninstall();

    expect($result->action)->toBe(ArtifactWriteAction::Created)
        ->and($connection->writtenStatements)->toBe([])
        ->and($connection->openedTransactions)->toBe(0)
        ->and($connection->queries)->toBe([]);
});
