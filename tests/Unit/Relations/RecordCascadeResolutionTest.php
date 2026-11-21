<?php

declare(strict_types=1);

use App\Enums\Engine\CascadeBehavior;
use App\Models\CustomRecord;
use App\Observers\RecordCascadeObserver;
use App\Support\Approvals\ApprovalInvalidator;
use App\Support\Engine\AncestorChainNotifier;
use App\Support\Engine\AuditRecorder;
use App\Support\Engine\RecordEndpointResolver;
use App\Support\Engine\RecordTreeQuery;
use App\Support\Engine\RollupOwnerStarter;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticQueryConnection;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->tenantId = (string) $this->tenant->getKey();

    $this->objectTypeId = ModelStub::ulid('cascade-object-type');
    $this->parentId = ModelStub::ulid('cascade-parent');
    $this->childId = ModelStub::ulid('cascade-child');
    $this->linkId = ModelStub::ulid('cascade-link');
    $this->relationshipTypeId = ModelStub::ulid('cascade-relationship-type');

    $this->parent = ModelStub::make(CustomRecord::class, [
        'id' => $this->parentId,
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->objectTypeId,
        'version' => 2,
    ]);

    $this->auditRecorder = Mockery::mock(AuditRecorder::class);
    $this->rollupStarter = Mockery::mock(RollupOwnerStarter::class);
    $this->rollupStarter->shouldReceive('startForRecordIds')->byDefault();

    $this->chainNotifier = Mockery::mock(AncestorChainNotifier::class);
    $this->chainNotifier->shouldReceive('notify')->byDefault();

    $this->approvals = Mockery::mock(ApprovalInvalidator::class);
    $this->approvals->shouldReceive('cancelOpen')->byDefault();

    $this->observer = new RecordCascadeObserver(
        $this->auditRecorder,
        Mockery::mock(RecordTreeQuery::class),
        $this->chainNotifier,
        $this->rollupStarter,
        Mockery::mock(RecordEndpointResolver::class),
        $this->approvals,
    );

    $this->objectTypeRow = [
        'id' => $this->objectTypeId,
        'tenant_id' => $this->tenantId,
        'slug' => 'companies',
        'key' => 'companies',
        'hierarchy_relationship_type_id' => null,
    ];

    /** @var callable(CascadeBehavior, ?string, list<string>):StaticQueryConnection */
    $this->connectionLinking = fn (CascadeBehavior $behavior, ?string $childDeletedAt, array $parentsOutside = []): StaticQueryConnection => StaticQueryConnection::install(
        fn (string $sql, array $bindings): array => match (true) {
            str_starts_with($sql, 'select distinct "from_record_id" from "record_links"') => array_map(
                static fn (string $id): array => ['from_record_id' => $id],
                $parentsOutside,
            ),
            str_contains($sql, 'from "record_links"') => in_array($this->parentId, $bindings, true) && !in_array($this->childId, $bindings, true)
                ? [[
                    'id' => $this->linkId,
                    'tenant_id' => $this->tenantId,
                    'relationship_type_id' => $this->relationshipTypeId,
                    'from_record_id' => $this->parentId,
                    'to_record_id' => $this->childId,
                    'to_record_type' => 'custom_record',
                ]]
                : [],
            str_contains($sql, 'from "relationship_types"') => [[
                'id' => $this->relationshipTypeId,
                'tenant_id' => $this->tenantId,
                'cascade_behavior' => $behavior->value,
            ]],
            str_contains($sql, 'from "custom_records"') => [[
                'id' => $this->childId,
                'tenant_id' => $this->tenantId,
                'object_type_id' => $this->objectTypeId,
                'version' => 5,
                'deleted_at' => $childDeletedAt,
            ]],
            str_contains($sql, 'from "object_types"') => [$this->objectTypeRow],
            default => [],
        },
        static fn (): int => 1,
    );
});

afterEach(function (): void {
    StaticQueryConnection::uninstall();
    AccessContext::forgetTenant();
    Mockery::close();
});

it('refuses the deletion while a locked relationship still holds a live record and writes nothing', function (): void {
    $connection = ($this->connectionLinking)(CascadeBehavior::Restrict, null);
    $this->auditRecorder->shouldNotReceive('record');

    expect(fn (): null => $this->observer->run($this->parent))->toThrow(ValidationException::class)
        ->and($connection->writtenStatements)->toBe([]);
});

it('lets the deletion pass when the record behind the locked relationship is already gone', function (): void {
    $connection = ($this->connectionLinking)(CascadeBehavior::Restrict, '2026-09-01T00:00:00+00:00');
    $this->auditRecorder->shouldReceive('record')->once();

    $this->observer->run($this->parent);

    expect($connection->writtenStatements)->toBe([]);
});

it('drops the link of a nullifying relationship and leaves the record behind it alive', function (): void {
    $connection = ($this->connectionLinking)(CascadeBehavior::Nullify, null);
    $this->auditRecorder->shouldReceive('record')->once();

    $this->observer->run($this->parent);

    expect($connection->writtenSqlOf('record_links'))->toHaveCount(1)
        ->and($connection->writtenStatements[0]['bindings'])->toContain($this->linkId)
        ->and($connection->writtenSqlOf('custom_records'))->toBe([]);
});

it('soft deletes the record behind a cascading relationship and audits it under its own version', function (): void {
    $connection = ($this->connectionLinking)(CascadeBehavior::Cascade, null);

    $audited = [];
    $this->auditRecorder->shouldReceive('record')
        ->twice()
        ->andReturnUsing(function (CustomRecord $record, array $old, array $new, int $version) use (&$audited): void {
            $audited[] = [(string) $record->getKey(), $version];
        });

    $this->observer->run($this->parent);

    expect($audited)->toBe([[$this->parentId, 2], [$this->childId, 5]])
        ->and($connection->writtenSqlOf('custom_records'))->toHaveCount(1)
        ->and($connection->writtenSqlOf('record_links'))->toBe([]);
});

it('hands the parents outside the deletion to the roll-up starter before the transaction opens', function (): void {
    $outsideParent = ModelStub::ulid('cascade-outside-parent');
    $connection = ($this->connectionLinking)(CascadeBehavior::Cascade, null, [$outsideParent]);
    $this->auditRecorder->shouldReceive('record')->twice();

    $started = [];
    $this->rollupStarter->shouldReceive('startForRecordIds')
        ->once()
        ->andReturnUsing(function (string $tenantId, array $recordIds) use (&$started, $connection): void {
            $started = [$tenantId, $recordIds, $connection->openedTransactions];
        });

    $this->observer->run($this->parent);

    expect($started)->toBe([$this->tenantId, [$outsideParent], 0])
        ->and($connection->openedTransactions)->toBeGreaterThan(0);
});

it('looks the record behind a locked relationship up past the row access rules of the deleting user', function (): void {
    $compiler = AccessContext::recordRuleCompiler();
    AccessContext::enforceRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'restricted-deleter'));
    AccessContext::rowAccessRules([$this->objectTypeId => [[[
        'combinator' => 'and',
        'conditions' => [['field' => 'region', 'operator' => 'equals', 'value' => 'north']],
    ]]]]);
    ($this->connectionLinking)(CascadeBehavior::Restrict, null);

    try {
        expect(fn (): null => $this->observer->run($this->parent))->toThrow(ValidationException::class)
            ->and($compiler->calls)->toBe([]);
    } finally {
        AccessContext::suspendRowAccess();
    }
});

it('soft deletes a cascaded record that the row access rules of the deleting user hide instead of leaving it behind', function (): void {
    $compiler = AccessContext::recordRuleCompiler();
    AccessContext::enforceRowAccess();
    AccessContext::actAs(AccessContext::user($this->tenant, [], 'restricted-deleter'));
    AccessContext::rowAccessRules([$this->objectTypeId => [[[
        'combinator' => 'and',
        'conditions' => [['field' => 'region', 'operator' => 'equals', 'value' => 'north']],
    ]]]]);
    $connection = ($this->connectionLinking)(CascadeBehavior::Cascade, null);
    $this->auditRecorder->shouldReceive('record')->twice();

    try {
        $this->observer->run($this->parent);
    } finally {
        AccessContext::suspendRowAccess();
    }

    expect($compiler->calls)->toBe([])
        ->and($connection->writtenSqlOf('custom_records'))->toHaveCount(1);
});

it('cancels the open approvals of a record the cascade deletes', function (): void {
    ($this->connectionLinking)(CascadeBehavior::Cascade, null);
    $this->auditRecorder->shouldReceive('record')->twice();

    $cancelled = [];
    $this->approvals->shouldReceive('cancelOpen')
        ->once()
        ->andReturnUsing(function (CustomRecord $record) use (&$cancelled): void {
            $cancelled[] = (string) $record->getKey();
        });

    $this->observer->run($this->parent);

    expect($cancelled)->toBe([$this->childId]);
});
