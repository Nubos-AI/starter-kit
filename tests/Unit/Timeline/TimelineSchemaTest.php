<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000052_create_timeline_entries_table.php');
});

it('creates the projection columns in the order the conventions demand', function (): void {
    expect($this->schema->columnsOf('timeline_entries'))->toBe([
        'id',
        'tenant_id',
        'record_id',
        'source_key',
        'source_id',
        'actor_id',
        'actor_type',
        'channel',
        'occurred_at',
        'payload',
        'created_at',
        'updated_at',
    ]);
});

it('stores every identifier as a ulid and the payload as jsonb', function (): void {
    expect($this->schema->columnDefinition('timeline_entries', 'id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('timeline_entries', 'tenant_id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('timeline_entries', 'record_id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('timeline_entries', 'source_id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('timeline_entries', 'actor_id'))->toStartWith('char(26)')
        ->and($this->schema->columnDefinition('timeline_entries', 'payload'))->toStartWith('jsonb');
});

it('keeps the actor columns and the payload nullable and the occurrence moment mandatory', function (): void {
    expect($this->schema->columnDefinition('timeline_entries', 'actor_id'))->toContain('null')
        ->and($this->schema->columnDefinition('timeline_entries', 'actor_type'))->toContain('null')
        ->and($this->schema->columnDefinition('timeline_entries', 'channel'))->toContain('null')
        ->and($this->schema->columnDefinition('timeline_entries', 'payload'))->toContain('null')
        ->and($this->schema->columnDefinition('timeline_entries', 'occurred_at'))->toContain('not null');
});

it('drops a projection row together with its tenant and its record', function (): void {
    expect($this->schema->hasForeignKey('timeline_entries', 'tenant_id', 'tenants', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('timeline_entries', 'record_id', 'custom_records', 'cascade'))->toBeTrue();
});

it('orders the cursor index by tenant, record and a descending occurrence key', function (): void {
    expect($this->schema->has(
        'CREATE INDEX idx_timeline_entries_record_cursor ON timeline_entries (tenant_id, record_id, occurred_at DESC, id DESC)',
    ))->toBeTrue();
});

it('orders the source tab index by tenant, record, source key and a descending occurrence key', function (): void {
    expect($this->schema->has(
        'CREATE INDEX idx_timeline_entries_record_source ON timeline_entries (tenant_id, record_id, source_key, occurred_at DESC, id DESC)',
    ))->toBeTrue();
});

it('carries no unique index at all so the same source may project twice', function (): void {
    expect($this->schema->has('create unique index'))->toBeFalse()
        ->and($this->schema->has('CREATE UNIQUE INDEX'))->toBeFalse();
});

it('carries no soft delete column because the projection is rebuilt, never archived', function (): void {
    expect($this->schema->columnsOf('timeline_entries'))->not->toContain('deleted_at');
});

it('drops the table again on the way down', function (): void {
    $down = SchemaShape::ofMigration('database/migrations/0001_01_01_000052_create_timeline_entries_table.php', 'down');

    expect($down->has('drop table if exists "timeline_entries"'))->toBeTrue();
});
