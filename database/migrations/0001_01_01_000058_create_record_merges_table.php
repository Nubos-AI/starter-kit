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
            'record_merges',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('target_record_id')->constrained('custom_records')->cascadeOnDelete();
                $table->foreignUlid('source_record_id')->constrained('custom_records')->cascadeOnDelete();
                $table->foreignUlid('merge_rule_id')->nullable()->index()->constrained('merge_rules')->nullOnDelete();
                $table->foreignUlid('actor_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->text('reason')->nullable();
                $table->jsonb('resolution');
                $table->jsonb('transfers');
                $table->timestamp('undone_at')->nullable();
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_record_merges_tenant_target ON record_merges (tenant_id, target_record_id, created_at DESC)');
        DB::statement('CREATE UNIQUE INDEX unq_record_merges_source ON record_merges (source_record_id) WHERE undone_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('record_merges');
    }
};
