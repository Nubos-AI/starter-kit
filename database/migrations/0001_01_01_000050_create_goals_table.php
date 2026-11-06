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
            'goals',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('owner_id')->index()->constrained('users')->cascadeOnDelete();
                $table->foreignUlid('report_id')->index()->constrained('reports')->cascadeOnDelete();
                $table->foreignUlid('target_user_id')->nullable()->index()->constrained('users')->cascadeOnDelete();
                $table->foreignUlid('target_team_id')->nullable()->index()->constrained('teams')->cascadeOnDelete();
                $table->boolean('includes_subteams')->default(false);
                $table->string('name');
                $table->string('scope_type');
                $table->string('scope_field_key')->nullable();
                $table->string('period_field_key')->nullable();
                $table->string('period_type');
                $table->string('direction');
                $table->decimal('target_value', 20, 4);
                $table->softDeletes();
                $table->timestamps();
            }
        );

        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->foreign('goal_id')->references('id')->on('goals')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE goals ADD CONSTRAINT chk_goals_scope_target CHECK ((scope_type = 'user' AND target_user_id IS NOT NULL AND target_team_id IS NULL AND scope_field_key IS NOT NULL) OR (scope_type = 'team' AND target_team_id IS NOT NULL AND target_user_id IS NULL AND scope_field_key IS NOT NULL) OR (scope_type = 'tenant' AND target_user_id IS NULL AND target_team_id IS NULL AND scope_field_key IS NULL))");
    }

    public function down(): void
    {
        Schema::table('dashboard_widgets', function (Blueprint $table): void {
            $table->dropForeign(['goal_id']);
        });

        Schema::dropIfExists('goals');
    }
};
