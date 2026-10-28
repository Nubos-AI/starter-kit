<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000054_create_aging_rules_table.php');
});

it('creates the aging rule columns in the order the conventions demand', function (): void {
    expect($this->schema->columnsOf('aging_rules'))->toBe([
        'id',
        'object_type_id',
        'name',
        'clock',
        'clock_field_key',
        'condition',
        'thresholds',
        'is_active',
        'triggers_automation',
        'deleted_at',
        'created_at',
        'updated_at',
    ]);
});

it('carries no tenant column because the object type owns the tenant binding', function (): void {
    expect($this->schema->columnsOf('aging_rules'))->not->toContain('tenant_id');
});

it('stores the thresholds as jsonb and keeps the condition optional', function (): void {
    expect($this->schema->columnDefinition('aging_rules', 'thresholds'))->toStartWith('jsonb')
        ->and($this->schema->columnDefinition('aging_rules', 'thresholds'))->toContain('not null')
        ->and($this->schema->columnDefinition('aging_rules', 'condition'))->toStartWith('jsonb')
        ->and($this->schema->columnDefinition('aging_rules', 'condition'))->not->toContain('not null');
});

it('defaults a new rule to active and to a disabled automation trigger', function (): void {
    expect($this->schema->columnDefinition('aging_rules', 'is_active'))->toContain('default \'1\'')
        ->and($this->schema->columnDefinition('aging_rules', 'triggers_automation'))->toContain('default \'0\'');
});

it('removes the rules of an object type once that object type is gone', function (): void {
    expect($this->schema->hasForeignKey('aging_rules', 'object_type_id', 'object_types', 'cascade'))->toBeTrue();
});

it('keeps the rule name unique per object type only while the rule is not soft deleted', function (): void {
    expect($this->schema->hasPartialUniqueIndex(
        'unq_aging_rules_type_name',
        'aging_rules',
        ['object_type_id', 'name'],
        'deleted_at IS NULL',
    ))->toBeTrue();
});

it('drops the table again on the way down', function (): void {
    $down = SchemaShape::ofMigration('database/migrations/0001_01_01_000054_create_aging_rules_table.php', 'down');

    expect($down->has('drop table if exists "aging_rules"'))->toBeTrue();
});
