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
            'dashboard_widgets',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('dashboard_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('report_id')->nullable()->index()->constrained('reports')->cascadeOnDelete();
                $table->ulid('goal_id')->nullable()->index();
                $table->string('title')->nullable();
                $table->string('chart_type')->nullable();
                $table->jsonb('definition')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->unsignedInteger('column_span')->default(1);
                $table->timestamps();

                $table->index(['tenant_id', 'dashboard_id'], 'idx_dashboard_widgets_tenant_dashboard');
            }
        );

        DB::statement('ALTER TABLE dashboard_widgets ADD CONSTRAINT chk_dashboard_widgets_source_exclusive CHECK (num_nonnulls(report_id, definition, goal_id) = 1)');

        DB::statement('ALTER TABLE dashboard_widgets ADD CONSTRAINT chk_dashboard_widgets_chart_type CHECK ((goal_id IS NULL) = (chart_type IS NOT NULL))');

        DB::statement('ALTER TABLE dashboard_widgets ADD CONSTRAINT chk_dashboard_widgets_column_span CHECK (column_span BETWEEN 1 AND 3)');
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
    }
};
