<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'reports',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('owner_id')->index()->constrained('users')->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->jsonb('filter_definition')->nullable();
                $table->string('aggregation_type');
                $table->string('aggregation_field_key')->nullable();
                $table->string('group_by_field_key')->nullable();
                $table->string('group_by_bucket')->nullable();
                $table->string('series_field_key')->nullable();
                $table->string('chart_type');
                $table->string('execution_mode')->default('viewer');
                $table->softDeletes();
                $table->timestamps();

                $table->index(['tenant_id', 'object_type_id'], 'idx_reports_tenant_object_type');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
