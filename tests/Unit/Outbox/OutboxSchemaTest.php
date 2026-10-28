<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000018_create_audit_and_outbox_tables.php');
});

it('keeps one outbox table that carries a topic, a subject and a payload next to the record columns', function (): void {
    expect($this->schema->columnsOf('outbox_events'))->toBe([
        'id',
        'tenant_id',
        'topic',
        'object_type_id',
        'record_id',
        'subject_type',
        'subject_id',
        'triggered_by_automation_id',
        'root_run_id',
        'relay_batch_id',
        'version',
        'changed_field_keys',
        'payload',
        'attempts',
        'created_at',
        'available_at',
        'published_at',
        'failed_at',
    ]);
});

it('requires a topic on every entry and leaves the record columns to the record change topic', function (string $column): void {
    expect($this->schema->columnDefinition('outbox_events', 'topic'))->toContain('not null')
        ->and($this->schema->columnDefinition('outbox_events', $column))->toContain('null')
        ->and($this->schema->columnDefinition('outbox_events', $column))->not->toContain('not null');
})->with(['object_type_id', 'record_id', 'version', 'changed_field_keys', 'subject_type', 'subject_id', 'payload']);

it('stores the payload as jsonb and starts every entry without an attempt', function (): void {
    expect($this->schema->columnDefinition('outbox_events', 'payload'))->toStartWith('jsonb')
        ->and($this->schema->columnDefinition('outbox_events', 'attempts'))->toContain("default '0'")
        ->and($this->schema->columnDefinition('outbox_events', 'attempts'))->toContain('not null');
});

it('lets the database refuse a record change entry without its record columns', function (): void {
    expect($this->schema->has(
        "ALTER TABLE outbox_events ADD CONSTRAINT chk_outbox_events_record_change CHECK (topic <> 'record.changed' OR (object_type_id IS NOT NULL AND record_id IS NOT NULL AND version IS NOT NULL AND changed_field_keys IS NOT NULL))",
    ))->toBeTrue();
});

it('indexes the pending entries of a topic in sequence order', function (): void {
    expect($this->schema->has('CREATE INDEX idx_outbox_events_pending_topic ON outbox_events (topic, sequence) WHERE published_at IS NULL AND failed_at IS NULL'))->toBeTrue()
        ->and($this->schema->has('CREATE INDEX idx_outbox_events_unpublished ON outbox_events (tenant_id, sequence) WHERE published_at IS NULL'))->toBeTrue();
});

it('drops the whole outbox again on the way down', function (): void {
    $down = SchemaShape::ofMigration('database/migrations/0001_01_01_000018_create_audit_and_outbox_tables.php', 'down');

    expect($down->has('drop table if exists "outbox_events"'))->toBeTrue();
});
