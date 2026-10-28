<?php

declare(strict_types=1);

use App\Enums\Audit\ActorType;
use App\Models\CustomRecord;
use App\Support\Governance\FieldEditorResolver;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();

    $this->resolver = new FieldEditorResolver;

    $this->record = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('audited-record'),
        'tenant_id' => $this->tenant->getKey(),
    ]);
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('runs no query at all for an empty field list', function (): void {
    $editor = null;

    $shape = QueryShape::attemptedBy(function () use (&$editor): void {
        $editor = $this->resolver->lastEditorOfFields($this->record, []);
    });

    expect($shape)->toBeNull()
        ->and($editor)->toBeNull();
});

it('reads only the audit rows of the record it was handed', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->lastEditorOfFields($this->record, ['amount']));

    expect($shape)->not->toBeNull()
        ->and($shape->targets('audit_entries'))->toBeTrue()
        ->and($shape->sql)->toContain('"auditable_type" = ?')
        ->and($shape->sql)->toContain('"auditable_id" = ?')
        ->and($shape->hasBinding($this->record->getMorphClass()))->toBeTrue()
        ->and($shape->hasBinding((string) $this->record->getKey()))->toBeTrue();
});

it('takes the tenant from the record and replaces the global tenant scope with it', function (): void {
    $foreign = ModelStub::make(CustomRecord::class, [
        'id' => ModelStub::ulid('foreign-record'),
        'tenant_id' => ModelStub::ulid('foreign-tenant'),
    ]);

    $shape = QueryShape::attemptedBy(fn () => $this->resolver->lastEditorOfFields($foreign, ['amount']));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"audit_entries"."tenant_id" = ?')
        ->and($shape->hasBinding(ModelStub::ulid('foreign-tenant')))->toBeTrue()
        ->and($shape->hasBinding((string) $this->tenant->getKey()))->toBeFalse();
});

it('still pins a tenant when none is bound to the container', function (): void {
    AccessContext::forgetTenant();

    $shape = QueryShape::attemptedBy(fn () => $this->resolver->lastEditorOfFields($this->record, ['amount']));

    expect($shape)->not->toBeNull()
        ->and($shape->blocksEveryRow())->toBeFalse()
        ->and($shape->hasBinding((string) $this->record->tenant_id))->toBeTrue();
});

it('counts only human edits and never an automation or an actorless row', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->lastEditorOfFields($this->record, ['amount']));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"actor_type" = ?')
        ->and($shape->hasBinding(ActorType::User->value))->toBeTrue()
        ->and($shape->sql)->toContain('"actor_id" is not null');
});

it('takes the union of the given field keys and reads the newest version first', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->lastEditorOfFields($this->record, ['amount', 'status']));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"field_key" in (?, ?)')
        ->and($shape->hasBinding('amount'))->toBeTrue()
        ->and($shape->hasBinding('status'))->toBeTrue()
        ->and($shape->sql)->toContain('order by "version" desc, "id" desc')
        ->and($shape->sql)->toContain('limit 1');
});

it('asks only for the actor column instead of the whole audit row', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->lastEditorOfFields($this->record, ['amount']));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toStartWith('select "actor_id" from "audit_entries"');
});

it('derives the creator from the first version and never from the deletion row', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->creatorOf($this->record));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"version" = ?')
        ->and($shape->hasBinding(1))->toBeTrue()
        ->and($shape->sql)->toContain('"field_key" != ?')
        ->and($shape->hasBinding('deleted_at'))->toBeTrue();
});

it('orders the creation lookup by the oldest change first so a later restore never wins', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->creatorOf($this->record));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('order by "changed_at" asc, "id" asc')
        ->and($shape->sql)->toContain('limit 1');
});

it('keeps the creation lookup inside the tenant of the record and among human actors', function (): void {
    $shape = QueryShape::attemptedBy(fn () => $this->resolver->creatorOf($this->record));

    expect($shape)->not->toBeNull()
        ->and($shape->sql)->toContain('"audit_entries"."tenant_id" = ?')
        ->and($shape->hasBinding((string) $this->record->tenant_id))->toBeTrue()
        ->and($shape->hasBinding(ActorType::User->value))->toBeTrue()
        ->and($shape->sql)->toContain('"actor_id" is not null');
});

it('stores no creator column on the record table because the audit trail carries it', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000014_create_custom_records_table.php');

    expect($schema->columnsOf('custom_records'))->not->toContain('created_by_id');
});

it('carries the field history index over tenant, morph target, field key and version', function (): void {
    $schema = SchemaShape::ofMigration('database/migrations/0001_01_01_000018_create_audit_and_outbox_tables.php');

    expect($schema->has(
        'create index "idx_audit_entries_field_history" on "audit_entries" ("tenant_id", "auditable_type", "auditable_id", "field_key", "version")',
    ))->toBeTrue();
});
