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
            'merge_rules',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('mode');
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_active')->default(true);
                $table->string('deny_reason')->nullable();
                $table->jsonb('condition')->nullable();
                $table->jsonb('field_strategies');
                $table->jsonb('transfer_policy');
                $table->jsonb('options');
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_merge_rules_type_name ON merge_rules (tenant_id, object_type_id, name) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_merge_rules_type_position ON merge_rules (tenant_id, object_type_id, position) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('merge_rules');
    }
};
