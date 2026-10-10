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
            'approval_process_stages',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('approval_process_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('approval_definition_stage_id')
                    ->index()
                    ->constrained()
                    ->cascadeOnDelete();
                $table->foreignUlid('assigned_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->boolean('escalation_applied')->default(false);
                $table->unsignedInteger('position');
                $table->unsignedInteger('attempt');
                $table->string('status');
                $table->timestamp('deadline_at')->nullable();
                $table->jsonb('escalation_added_delegations')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('decided_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_approval_process_stages_attempt ON approval_process_stages (tenant_id, approval_process_id, attempt, position) WHERE deleted_at IS NULL');

        DB::statement('CREATE INDEX idx_approval_process_stages_deadline ON approval_process_stages (tenant_id, status, deadline_at) WHERE deleted_at IS NULL AND deadline_at IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_process_stages');
    }
};
