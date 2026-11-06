<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000033_create_reminder_tasks_table.php');
});

it('creates the reminder columns in the order the conventions demand', function (): void {
    expect($this->schema->columnsOf('reminder_tasks'))->toBe([
        'id',
        'tenant_id',
        'owner_id',
        'creator_id',
        'assignee_id',
        'record_id',
        'reminder_type_id',
        'series_id',
        'source_type',
        'source_id',
        'due_at',
        'subject',
        'note',
        'recurrence_frequency',
        'recurrence_interval',
        'recurrence_ends_at',
        'recurrence_anchor_at',
        'recurrence_exceptions',
        'series_occurrence_at',
        'done_at',
        'notified_at',
        'created_at',
        'updated_at',
    ]);
});

it('keeps a reminder alive when its assignee or its type is removed and drops it with its record', function (): void {
    expect($this->schema->hasForeignKey('reminder_tasks', 'assignee_id', 'users', 'set null'))->toBeTrue()
        ->and($this->schema->hasForeignKey('reminder_tasks', 'reminder_type_id', 'reminder_types', 'set null'))->toBeTrue()
        ->and($this->schema->hasForeignKey('reminder_tasks', 'record_id', 'custom_records', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('reminder_tasks', 'owner_id', 'users', 'cascade'))->toBeTrue()
        ->and($this->schema->hasForeignKey('reminder_tasks', 'creator_id', 'users', 'cascade'))->toBeTrue();
});

it('indexes the open reminders of one assignee by their due date', function (): void {
    expect($this->schema->has('create index "idx_reminder_tasks_open" on "reminder_tasks" ("tenant_id", "assignee_id", "done_at", "due_at")'))->toBeTrue()
        ->and($this->schema->has('create index "idx_reminder_tasks_record" on "reminder_tasks" ("record_id")'))->toBeTrue();
});

it('lets a reminder point at an optional source of any registered type, indexed inside its tenant', function (): void {
    expect($this->schema->columnDefinition('reminder_tasks', 'source_type'))->toContain('varchar')
        ->and($this->schema->columnDefinition('reminder_tasks', 'source_type'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'source_id'))->toContain('char(26)')
        ->and($this->schema->columnDefinition('reminder_tasks', 'source_id'))->not->toContain('not null')
        ->and($this->schema->has('create index "idx_reminder_tasks_tenant_id_source_type_source_id" on "reminder_tasks" ("tenant_id", "source_type", "source_id")'))->toBeTrue();
});

it('lets a reminder stand without a record, an assignee or a due date', function (): void {
    expect($this->schema->columnDefinition('reminder_tasks', 'record_id'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'assignee_id'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'due_at'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'subject'))->toContain('not null');
});

it('lets a reminder repeat by an optional frequency, interval and end without a native enum', function (): void {
    expect($this->schema->columnDefinition('reminder_tasks', 'recurrence_frequency'))->toContain('varchar')
        ->and($this->schema->columnDefinition('reminder_tasks', 'recurrence_frequency'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'recurrence_interval'))->toContain('smallint')
        ->and($this->schema->columnDefinition('reminder_tasks', 'recurrence_interval'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'recurrence_ends_at'))->toContain('timestamp')
        ->and($this->schema->columnDefinition('reminder_tasks', 'recurrence_ends_at'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'recurrence_exceptions'))->toContain('jsonb')
        ->and($this->schema->columnDefinition('reminder_tasks', 'series_occurrence_at'))->not->toContain('not null');
});

it('lets a reminder moved out of a series point back at its series and outlive it', function (): void {
    expect($this->schema->hasForeignKey('reminder_tasks', 'series_id', 'reminder_tasks', 'set null'))->toBeTrue()
        ->and($this->schema->columnDefinition('reminder_tasks', 'series_id'))->not->toContain('not null');
});

it('drops the table again on the way down', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000033_create_reminder_tasks_table.php', 'down')
        ->has('drop table if exists "reminder_tasks"'))->toBeTrue();
});
