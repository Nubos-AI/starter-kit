<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->migration = 'database/migrations/0001_01_01_000078_add_principal_decision_index_to_approval_events_table.php';
});

it('lets the database refuse a second decision of the same effective voter on one stage', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->statements)->toHaveCount(1)
        ->and($schema->hasPartialUniqueIndex(
            'unq_approval_events_principal',
            'approval_events',
            ['tenant_id', 'approval_process_stage_id', 'COALESCE(on_behalf_of_id, actor_id)'],
            "type IN ('approved', 'rejected')",
        ))->toBeTrue();
});

it('drops exactly that index again on the way down', function (): void {
    $schema = SchemaShape::ofMigration($this->migration, 'down');

    expect($schema->statements)->toBe(['DROP INDEX IF EXISTS unq_approval_events_principal']);
});
