<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->dashboards = SchemaShape::ofMigration('database/migrations/0001_01_01_000047_create_dashboards_table.php');
    $this->reports = SchemaShape::ofMigration('database/migrations/0001_01_01_000046_create_reports_table.php');
});

it('marks a shipped dashboard with a system flag that defaults to false, right after the foreign keys', function (): void {
    expect($this->dashboards->columnsOf('dashboards'))->toBe([
        'id',
        'tenant_id',
        'owner_id',
        'is_system',
        'is_tenant_wide',
        'name',
        'description',
        'deleted_at',
        'created_at',
        'updated_at',
    ])
        ->and($this->dashboards->columnDefinition('dashboards', 'is_system'))->toContain("boolean not null default '0'");
});

it('marks a shipped report with a system flag that defaults to false, right after the foreign keys', function (): void {
    expect(array_slice($this->reports->columnsOf('reports'), 0, 5))->toBe([
        'id',
        'tenant_id',
        'owner_id',
        'object_type_id',
        'is_system',
    ])
        ->and($this->reports->columnDefinition('reports', 'is_system'))->toContain("boolean not null default '0'");
});
