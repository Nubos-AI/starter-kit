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
            'formula_backfill_runs',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('field_definition_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('user_id')
                    ->nullable()
                    ->index()
                    ->constrained()
                    ->nullOnDelete();
                $table->string('status')->default('pending');
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->ulid('cursor_id')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            }
        );

        DB::statement("CREATE UNIQUE INDEX unq_formula_backfill_runs_active ON formula_backfill_runs (tenant_id, field_definition_id) WHERE status IN ('pending', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_backfill_runs');
    }
};
