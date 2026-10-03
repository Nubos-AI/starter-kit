<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->string('auditable_type');
            $table->char('auditable_id', 26);
            $table->string('field_key');
            $table->jsonb('old_value')->nullable();
            $table->jsonb('new_value')->nullable();
            $table->char('actor_id', 26)->nullable();
            $table->string('actor_type')->nullable();
            $table->unsignedInteger('version');
            $table->timestamp('changed_at');

            $table->index(['tenant_id', 'auditable_type', 'auditable_id', 'changed_at'], 'idx_audit_entries_tenant_record');
            $table->index(['auditable_type', 'auditable_id', 'version'], 'idx_audit_entries_record_version');
            $table->index(
                ['tenant_id', 'auditable_type', 'auditable_id', 'field_key', 'version'],
                'idx_audit_entries_field_history',
            );
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_entries_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_entries is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_audit_entries_append_only
                BEFORE UPDATE OR DELETE ON audit_entries
                FOR EACH ROW EXECUTE FUNCTION audit_entries_reject_mutation()
        SQL);

        Schema::create('outbox_events', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('tenant_id', 26);
            $table->char('object_type_id', 26);
            $table->char('record_id', 26);
            $table->char('triggered_by_automation_id', 26)->nullable();
            $table->char('root_run_id', 26)->nullable();
            $table->unsignedInteger('version');
            $table->jsonb('changed_field_keys');
            $table->timestamp('created_at');
            $table->timestamp('published_at')->nullable();
        });

        DB::statement('ALTER TABLE outbox_events ADD COLUMN sequence bigint GENERATED ALWAYS AS IDENTITY');
        DB::statement('CREATE UNIQUE INDEX unq_outbox_events_sequence ON outbox_events (sequence)');
        DB::statement('CREATE INDEX idx_outbox_events_unpublished ON outbox_events (tenant_id, sequence) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        DB::statement('DROP TRIGGER IF EXISTS trg_audit_entries_append_only ON audit_entries');
        Schema::dropIfExists('audit_entries');
        DB::statement('DROP FUNCTION IF EXISTS audit_entries_reject_mutation()');
    }
};
