<?php

declare(strict_types=1);

use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000071_create_maintenance_locks_table.php');
});

it('keeps the lock history readable once the acquiring or releasing user is gone', function (): void {
    expect($this->schema->hasForeignKey('maintenance_locks', 'acquired_by_id', 'users', 'set null'))->toBeTrue()
        ->and($this->schema->hasForeignKey('maintenance_locks', 'released_by_id', 'users', 'set null'))->toBeTrue()
        ->and($this->schema->hasForeignKey('maintenance_locks', 'tenant_id', 'tenants', 'cascade'))->toBeTrue();
});

it('lets a tenant hold one active lock at a time and keeps every released lock', function (): void {
    expect($this->schema->hasPartialUniqueIndex(
        'unq_maintenance_locks_active',
        'maintenance_locks',
        ['tenant_id'],
        "status = 'active'",
    ))->toBeTrue();
});
