<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000018_create_audit_and_outbox_tables.php');
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
