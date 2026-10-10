<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000014_create_custom_records_table.php');
});

it('creates the record columns in the order the conventions demand', function (): void {
    expect($this->schema->columnsOf('custom_records'))->toBe([
        'id',
        'tenant_id',
        'team_id',
        'owner_id',
        'object_type_id',
        'merged_into_record_id',
        'record_number',
        'external_reference_id',
        'deletion_reason',
        'version',
        'data',
        'merged_at',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});

it('stores the identifier as a ulid and the payload as jsonb', function (): void {
    expect($this->schema->columnDefinition('custom_records', 'id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('custom_records', 'tenant_id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('custom_records', 'data'))->toStartWith('jsonb');
});

it('cascades a deleted tenant and detaches a deleted owner', function (): void {
    expect($this->schema->hasForeignKey('custom_records', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('custom_records', 'object_type_id', 'object_types', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('custom_records', 'owner_id', 'users', 'set null'))->toBeTrue()
        ->and($this->schema->hasForeignKey('custom_records', 'team_id', 'teams', 'set null'))->toBeTrue();
});

it('keeps the tenant unique indexes partial so soft deleted rows do not block a reuse', function (): void {
    expect($this->schema->hasPartialUniqueIndex(
        'unq_custom_records_tenant_number',
        'custom_records',
        ['tenant_id', 'record_number'],
        'deleted_at IS NULL',
    ))->toBeTrue()
        ->and($this->schema->hasPartialUniqueIndex(
            'unq_custom_records_tenant_extref',
            'custom_records',
            ['tenant_id', 'external_reference_id'],
            'deleted_at IS NULL',
        ))->toBeTrue();
});

it('indexes the payload for jsonb containment lookups', function (): void {
    expect($this->schema->has('USING gin (data jsonb_path_ops)'))->toBeTrue();
});

it('qualifies every tenant scoped lookup index by the tenant first', function (): void {
    expect($this->schema->has('ON custom_records (tenant_id, object_type_id, created_at) WHERE deleted_at IS NULL'))->toBeTrue()
        ->and($this->schema->has('ON custom_records (tenant_id, created_at, id) WHERE deleted_at IS NULL'))->toBeTrue()
        ->and($this->schema->has('ON custom_records (tenant_id, owner_id) WHERE deleted_at IS NULL'))->toBeTrue();
});

it('drops the table again on the way down', function (): void {
    $down = SchemaShape::ofMigration('database/migrations/0001_01_01_000014_create_custom_records_table.php', 'down');

    expect($down->has('drop table if exists "custom_records"'))->toBeTrue();
});

it('renders a ulid primary key as a fixed width char column', function (): void {
    $schema = SchemaShape::ofBlueprint('probe_table', static function (Blueprint $table): void {
        $table->ulid('id')->primary();
        $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
        $table->softDeletes();
        $table->timestamps();
    });

    expect($schema->columnsOf('probe_table'))->toBe(['id', 'tenant_id', 'deleted_at', 'created_at', 'updated_at'])
        ->and($schema->hasForeignKey('probe_table', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});
