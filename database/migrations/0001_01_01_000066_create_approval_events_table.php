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
        Schema::create(
            'approval_events',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('approval_process_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('approval_process_stage_id')
                    ->nullable()
                    ->index()
                    ->constrained()
                    ->cascadeOnDelete();
                $table->foreignUlid('actor_id')->nullable()->index()->constrained('users')->restrictOnDelete();
                $table->foreignUlid('on_behalf_of_id')->nullable()->index()->constrained('users')->restrictOnDelete();
                $table->string('type');
                $table->text('reason')->nullable();
                $table->jsonb('payload')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_approval_events_process ON approval_events (tenant_id, approval_process_id, occurred_at)');

        DB::statement("CREATE UNIQUE INDEX unq_approval_events_decision ON approval_events (tenant_id, approval_process_stage_id, actor_id) WHERE type IN ('approved', 'rejected')");

        DB::statement("ALTER TABLE approval_events ADD CONSTRAINT chk_approval_events_decision_actor CHECK (type NOT IN ('approved', 'rejected') OR (approval_process_stage_id IS NOT NULL AND (actor_id IS NOT NULL OR COALESCE(payload @> '{\"actor_unmatched\": true}'::jsonb, false))))");

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION approval_events_reject_mutation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND pg_trigger_depth() > 1 THEN
                    RETURN OLD;
                END IF;

                RAISE EXCEPTION 'approval_events is append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_approval_events_append_only
                BEFORE UPDATE OR DELETE ON approval_events
                FOR EACH ROW EXECUTE FUNCTION approval_events_reject_mutation()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_approval_events_append_only ON approval_events');
        Schema::dropIfExists('approval_events');
        DB::statement('DROP FUNCTION IF EXISTS approval_events_reject_mutation()');
    }
};
