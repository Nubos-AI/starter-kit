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
            'approval_definitions',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->string('anchor_type');
                $table->ulid('anchor_id')->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('exclusions');
                $table->softDeletes();
                $table->timestamps();

                $table->index(['anchor_type', 'anchor_id'], 'idx_approval_definitions_anchor');
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_approval_definitions_anchor ON approval_definitions (tenant_id, anchor_type, anchor_id) WHERE anchor_id IS NOT NULL AND deleted_at IS NULL');

        DB::statement('CREATE UNIQUE INDEX unq_approval_definitions_anchor_wide ON approval_definitions (tenant_id, anchor_type) WHERE anchor_id IS NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_definitions');
    }
};
