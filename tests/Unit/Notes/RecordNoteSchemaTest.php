<?php

declare(strict_types=1);

use App\Models\RecordNote;
use Tests\Support\AccessContext;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000053_create_record_notes_table.php');
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('creates the note columns in the order the conventions demand', function (): void {
    expect($this->schema->columnsOf('record_notes'))->toBe([
        'id',
        'tenant_id',
        'record_id',
        'author_id',
        'body',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});

it('stores the body as unbounded text so a long note is never truncated', function (): void {
    expect($this->schema->columnDefinition('record_notes', 'body'))->toStartWith('text')
        ->and($this->schema->columnDefinition('record_notes', 'body'))->toContain('not null');
});

it('keeps a note readable once its author is removed for good and drops it with its record', function (): void {
    expect($this->schema->hasForeignKey('record_notes', 'author_id', 'users', 'set null'))->toBeTrue()
        ->and($this->schema->hasForeignKey('record_notes', 'record_id', 'custom_records', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('record_notes', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('carries a tenant and record index restricted to the undeleted notes, newest first', function (): void {
    expect($this->schema->has(
        'CREATE INDEX idx_record_notes_tenant_record ON record_notes (tenant_id, record_id, created_at DESC) WHERE deleted_at IS NULL',
    ))->toBeTrue();
});

it('drops the table again on the way down', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000053_create_record_notes_table.php', 'down')
        ->has('drop table if exists "record_notes"'))->toBeTrue();
});

it('pins every note query to the bound tenant and hides the deleted notes', function (): void {
    $tenant = AccessContext::tenant('note-schema-tenant');

    $shape = QueryShape::of(RecordNote::class);

    expect($shape->isScopedToTenant('record_notes', (string) $tenant->getKey()))->toBeTrue()
        ->and($shape->hidesSoftDeleted('record_notes'))->toBeTrue();
});

it('blocks every note when no tenant is bound', function (): void {
    AccessContext::forgetTenant();

    expect(QueryShape::of(RecordNote::class)->blocksEveryRow())->toBeTrue();
});
