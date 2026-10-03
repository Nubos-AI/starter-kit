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
            'approval_processes',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('record_id')
                    ->nullable()
                    ->index()
                    ->constrained('custom_records')
                    ->cascadeOnDelete();
                $table->foreignUlid('approval_definition_id')->index()->constrained()->cascadeOnDelete();
                $table->ulidMorphs('anchor');
                $table->foreignUlid('triggered_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->string('status');
                $table->unsignedInteger('attempt')->default(1);
                $table->unsignedInteger('current_stage_position')->nullable();
                $table->unsignedInteger('record_version')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_approval_processes_record_status ON approval_processes (tenant_id, record_id, status) WHERE deleted_at IS NULL');

        DB::statement("CREATE UNIQUE INDEX unq_approval_processes_pending ON approval_processes (tenant_id, record_id, anchor_type, anchor_id) WHERE status = 'pending' AND deleted_at IS NULL AND record_id IS NOT NULL");

        DB::statement("CREATE UNIQUE INDEX unq_approval_processes_pending_anchor ON approval_processes (tenant_id, anchor_type, anchor_id) WHERE status = 'pending' AND deleted_at IS NULL AND record_id IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_processes');
    }
};
