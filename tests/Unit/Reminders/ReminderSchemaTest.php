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
        'due_at',
        'subject',
        'note',
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

it('lets a reminder stand without a record, an assignee or a due date', function (): void {
    expect($this->schema->columnDefinition('reminder_tasks', 'record_id'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'assignee_id'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'due_at'))->not->toContain('not null')
        ->and($this->schema->columnDefinition('reminder_tasks', 'subject'))->toContain('not null');
});

it('drops the table again on the way down', function (): void {
    expect(SchemaShape::ofMigration('database/migrations/0001_01_01_000033_create_reminder_tasks_table.php', 'down')
        ->has('drop table if exists "reminder_tasks"'))->toBeTrue();
});
