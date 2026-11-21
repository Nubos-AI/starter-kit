<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000033_create_reminder_tasks_table.php');
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
    expect($this->schema->has('create index "idx_reminder_tasks_tenant_id_source_type_source_id" on "reminder_tasks" ("tenant_id", "source_type", "source_id")'))->toBeTrue();
});

it('lets a reminder moved out of a series point back at its series and outlive it', function (): void {
    expect($this->schema->hasForeignKey('reminder_tasks', 'series_id', 'reminder_tasks', 'set null'))->toBeTrue();
});
