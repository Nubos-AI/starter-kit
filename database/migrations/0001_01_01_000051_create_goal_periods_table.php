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
            'goal_periods',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('goal_id')->index()->constrained()->cascadeOnDelete();
                $table->timestamp('period_start');
                $table->timestamp('period_end');
                $table->decimal('current_value', 20, 4)->nullable();
                $table->timestamp('calculated_at')->nullable();
                $table->jsonb('triggered_thresholds')->default('{}');
                $table->timestamps();

                $table->unique(['goal_id', 'period_start'], 'unq_goal_periods_goal_period_start');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_periods');
    }
};
