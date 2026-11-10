<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000073_create_activity_types_and_record_activities_tables.php');
});

it('creates the activity columns in the order the conventions demand', function (): void {
    expect($this->schema->columnsOf('record_activities'))->toBe([
        'id',
        'tenant_id',
        'record_id',
        'activity_type_id',
        'assignee_id',
        'creator_id',
        'source_type',
        'source_id',
        'subject',
        'occurred_at',
        'result',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});

it('lets an activity stand without a record and drops it together with the record it belongs to', function (): void {
    expect($this->schema->columnDefinition('record_activities', 'record_id'))->toContain('char(26)')
        ->and($this->schema->columnDefinition('record_activities', 'record_id'))->not->toContain('not null')
        ->and($this->schema->hasForeignKey('record_activities', 'record_id', 'custom_records', 'cascade'))->toBeTrue();
});

it('lets an activity point at an optional source of any registered type, indexed inside its tenant', function (): void {
    expect($this->schema->columnDefinition('record_activities', 'source_type'))->toContain('varchar')
        ->and($this->schema->columnDefinition('record_activities', 'source_type'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('record_activities', 'source_id'))->toContain('char(26)')
        ->and($this->schema->columnDefinition('record_activities', 'source_id'))->not->toContain('not null')
        ->and($this->schema->has('create index "idx_record_activities_tenant_id_source_type_source_id" on "record_activities" ("tenant_id", "source_type", "source_id")'))->toBeTrue();
});

it('drops both tables again on the way down', function (): void {
    $down = SchemaShape::ofMigration('database/migrations/0001_01_01_000073_create_activity_types_and_record_activities_tables.php', 'down');

    expect($down->has('drop table if exists "record_activities"'))->toBeTrue()
        ->and($down->has('drop table if exists "activity_types"'))->toBeTrue();
});
