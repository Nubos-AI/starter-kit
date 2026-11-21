<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000073_create_activity_types_and_record_activities_tables.php');
});

it('drops an activity together with the record it belongs to', function (): void {
    expect($this->schema->hasForeignKey('record_activities', 'record_id', 'custom_records', 'cascade'))->toBeTrue();
});

it('lets an activity point at an optional source of any registered type, indexed inside its tenant', function (): void {
    expect($this->schema->has('create index "idx_record_activities_tenant_id_source_type_source_id" on "record_activities" ("tenant_id", "source_type", "source_id")'))->toBeTrue();
});
