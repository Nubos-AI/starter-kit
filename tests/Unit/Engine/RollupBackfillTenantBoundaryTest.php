<?php

declare(strict_types=1);

use App\Actions\Engine\RequestRollupBackfillAction;
use App\Enums\CustomFields\FieldType;
use App\Models\FieldDefinition;
use App\Support\Engine\RollupDebounceStarter;
use Illuminate\Contracts\Config\Repository;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('reads only the links of the rollup field tenant when it requests the owner recomputes', function (): void {
    $tenantId = ModelStub::ulid('rollup-tenant');
    $relationshipTypeId = ModelStub::ulid('rollup-relationship');

    $field = ModelStub::make(FieldDefinition::class, [
        'id' => ModelStub::ulid('rollup-field'),
        'tenant_id' => $tenantId,
        'object_type_id' => ModelStub::ulid('rollup-owner-type'),
        'field_type' => FieldType::Rollup->value,
        'config' => json_encode(['relationship_type_id' => $relationshipTypeId]),
    ]);

    $config = Mockery::mock(Repository::class);
    $config->shouldReceive('get')->with('engine.field_maintenance.backfill_batch_size')->andReturn(100);

    $action = app()->make(RequestRollupBackfillAction::class, ['starter' => Mockery::mock(RollupDebounceStarter::class), 'config' => $config]);

    $attempt = QueryShape::attemptedBy(fn (): int => $action->execute($field));

    expect($attempt)->not->toBeNull()
        ->and($attempt?->targets('record_links'))->toBeTrue()
        ->and($attempt?->isScopedToTenant('record_links', $tenantId))->toBeTrue()
        ->and($attempt?->hasBinding($relationshipTypeId))->toBeTrue();
});
