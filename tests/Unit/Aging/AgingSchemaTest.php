<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000054_create_aging_rules_table.php');
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
