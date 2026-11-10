<?php

declare(strict_types=1);

use App\Activities\Engine\RecomputeRollupsActivity;
use App\DTOs\Engine\RecordChangeBatch;
use App\Enums\CustomFields\FieldType;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\RollupDebounceStarter;
use App\Support\Engine\RollupOwnerStarter;
use App\Support\Engine\RollupRecomputer;
use Illuminate\Support\Facades\DB;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Workflow\WorkflowRunInterface;
use Tests\Support\AccessContext;
use Tests\Support\Doubles\StaticObjectTypeFieldLookup;
use Tests\Support\ModelStub;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenantId = (string) AccessContext::tenant('scenario-tenant')->getKey();
    $this->typeIds = [];
    $this->relationshipIds = [];

    foreach (['deal', 'item', 'organization', 'person', 'product'] as $type) {
        $this->typeIds[$type] = ModelStub::ulid("scenario-type-{$type}");
    }

    foreach (['organization_deals', 'person_deals', 'deal_items', 'product_items'] as $relationship) {
        $this->relationshipIds[$relationship] = ModelStub::ulid("scenario-relationship-{$relationship}");
    }

    $this->field = fn (string $type, string $key, FieldType $fieldType, array $config = []): FieldDefinition => ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid("scenario-field-{$type}-{$key}"),
        'object_type_id' => $this->typeIds[$type],
        'key' => $key,
        'field_type' => $fieldType,
        'config' => $config,
    ]);

    $lookup = new StaticObjectTypeFieldLookup;
    $lookup->withFields($this->typeIds['deal'], [
        ($this->field)('deal', 'name', FieldType::TextShort),
        ($this->field)('deal', 'items_count', FieldType::Rollup, ['aggregate' => 'count', 'relationship_type_id' => $this->relationshipIds['deal_items']]),
        ($this->field)('deal', 'products_total', FieldType::Rollup, ['aggregate' => 'sum', 'source_field_key' => 'total', 'relationship_type_id' => $this->relationshipIds['deal_items']]),
    ]);

    foreach (['item', 'organization', 'person', 'product'] as $type) {
        $lookup->withFields($this->typeIds[$type], [($this->field)($type, 'name', FieldType::TextShort)]);
    }

    app()->instance(ObjectTypeFieldLookup::class, $lookup);

    $recomputer = Mockery::mock(RollupRecomputer::class);
    $recomputer->shouldReceive('isCascadeRelevant')
        ->andReturnUsing(fn (string $tenantId, string $objectTypeId): bool => in_array($objectTypeId, [$this->typeIds['deal'], $this->typeIds['item']], true));
    app()->instance(RollupRecomputer::class, $recomputer);

    $this->startCount = 0;

    $client = Mockery::mock(WorkflowClientInterface::class);
    $client->shouldReceive('newWorkflowStub')->andReturn(new stdClass);
    $client->shouldReceive('startWithSignal')->andReturnUsing(function (): WorkflowRunInterface {
        $this->startCount++;

        return Mockery::mock(WorkflowRunInterface::class);
    });
    app()->instance(WorkflowClientInterface::class, $client);
    app()->forgetInstance(RollupDebounceStarter::class);

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('beginTransaction', 'commit', 'rollBack', 'inTransaction')->andReturn(true);
    $pdo->shouldReceive('exec')->andReturn(0);
    DB::connection()->setPdo($pdo);

    $this->record = fn (string $type, string $seed): CustomRecord => ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid("scenario-record-{$seed}"),
        'tenant_id' => $this->tenantId,
        'object_type_id' => $this->typeIds[$type],
    ]);

    $this->link = function (CustomRecord $owner, string $relationship): void {
        DB::transaction(fn () => app(RollupOwnerStarter::class)->startForLinkOwner($owner, $this->relationshipIds[$relationship]));
    };

    $this->createDealWithThreeItems = function (): void {
        $deal = ($this->record)('deal', 'deal');
        $product = ($this->record)('product', 'product');

        ($this->link)(($this->record)('organization', 'organization'), 'organization_deals');
        ($this->link)(($this->record)('person', 'person'), 'person_deals');

        foreach (['first', 'second', 'third'] as $item) {
            ($this->link)($deal, 'deal_items');
            ($this->link)($product, 'product_items');
        }
    };

    $this->relayCreatedRecords = function (): void {
        $changes = [[$this->typeIds['deal'], ModelStub::ulid('scenario-record-deal')]];

        foreach (['first', 'second', 'third'] as $item) {
            $changes[] = [$this->typeIds['item'], ModelStub::ulid("scenario-record-item-{$item}")];
        }

        app(RecomputeRollupsActivity::class)->recomputeRollups(RecordChangeBatch::fromArray(array_map(
            fn (array $change, int $sequence): array => [
                'tenant_id' => $this->tenantId,
                'object_type_id' => $change[0],
                'record_id' => $change[1],
                'version' => 1,
                'sequence' => $sequence + 1,
                'changed_field_keys' => ['name', 'total'],
            ],
            $changes,
            array_keys($changes),
        )));
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
    Mockery::close();
});

it('starts seven roll-up recomputes for a deal with three items created action by action', function (): void {
    ($this->createDealWithThreeItems)();
    ($this->relayCreatedRecords)();

    expect($this->startCount)->toBe(7);
});

it('starts five roll-up recomputes for a deal with three items created in one transaction', function (): void {
    DB::transaction(fn () => ($this->createDealWithThreeItems)());
    ($this->relayCreatedRecords)();

    expect($this->startCount)->toBe(5);
});
