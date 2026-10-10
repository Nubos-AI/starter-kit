<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use Illuminate\Database\QueryException;
use Tests\Support\AccessContext;
use Tests\Support\ModelStub;
use Tests\Support\QueryShape;
use Tests\Support\SchemaShape;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

beforeEach(function (): void {
    $this->tenant = AccessContext::tenant();
    $this->entry = ModelStub::make(AuditEntry::class, [
        'tenant_id' => $this->tenant->getKey(),
        'version' => 1,
    ]);
    $this->migration = 'database/migrations/0001_01_01_000018_create_audit_and_outbox_tables.php';

    /** @var callable(Closure):RuntimeException */
    $this->refusalWithoutAQuery = function (Closure $mutation): RuntimeException {
        $thrown = null;

        $shape = QueryShape::attemptedBy(static function () use ($mutation, &$thrown): void {
            try {
                $mutation();
            } catch (RuntimeException $exception) {
                $thrown = $exception;
            }
        });

        expect($shape)->toBeNull();

        if (!$thrown instanceof RuntimeException) {
            $this->fail('the model let the mutation through');
        }

        return $thrown;
    };
});

afterEach(function (): void {
    AccessContext::forgetTenant();
});

it('refuses to update an audit entry before the statement ever leaves the model', function (): void {
    $thrown = ($this->refusalWithoutAQuery)(fn (): bool => $this->entry->update(['version' => 99]));

    expect($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and($thrown->getMessage())->toBe(__('i18n.backend.models.audit_entry.audit_entries_is_append_only_and_cannot_be_updated'));
});

it('refuses to delete an audit entry before the statement ever leaves the model', function (): void {
    $thrown = ($this->refusalWithoutAQuery)(fn (): ?bool => $this->entry->delete());

    expect($thrown)->not->toBeInstanceOf(QueryException::class)
        ->and($thrown->getMessage())->toBe(__('i18n.backend.models.audit_entry.audit_entries_is_append_only_and_cannot_be_deleted'));
});

it('installs a row level trigger that rejects an update or a delete that bypasses the model', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->has('RAISE EXCEPTION \'audit_entries is append-only: % is not permitted\', TG_OP'))->toBeTrue()
        ->and($schema->has('CREATE TRIGGER trg_audit_entries_append_only'))->toBeTrue()
        ->and($schema->has('BEFORE UPDATE OR DELETE ON audit_entries'))->toBeTrue()
        ->and($schema->has('FOR EACH ROW EXECUTE FUNCTION audit_entries_reject_mutation()'))->toBeTrue();
});

it('drops the append only trigger again on the way down', function (): void {
    $down = SchemaShape::ofMigration($this->migration, 'down');

    expect($down->has('DROP TRIGGER IF EXISTS trg_audit_entries_append_only ON audit_entries'))->toBeTrue()
        ->and($down->has('drop table if exists "audit_entries"'))->toBeTrue()
        ->and($down->has('drop table if exists "outbox_events"'))->toBeTrue();
});

it('keeps the audit columns in the order the conventions demand and without a soft delete', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->columnsOf('audit_entries'))->toBe([
        'id',
        'tenant_id',
        'auditable_type',
        'auditable_id',
        'field_key',
        'old_value',
        'new_value',
        'actor_id',
        'actor_type',
        'version',
        'changed_at',
    ])
        ->and($schema->columnDefinition('audit_entries', 'old_value'))->toStartWith('jsonb')
        ->and($schema->columnDefinition('audit_entries', 'new_value'))->toStartWith('jsonb');
});

it('indexes the audit history by tenant and by record version', function (): void {
    $schema = SchemaShape::ofMigration($this->migration);

    expect($schema->has('idx_audit_entries_tenant_record'))->toBeTrue()
        ->and($schema->has('idx_audit_entries_record_version'))->toBeTrue()
        ->and($schema->has('idx_audit_entries_field_history'))->toBeTrue();
});
