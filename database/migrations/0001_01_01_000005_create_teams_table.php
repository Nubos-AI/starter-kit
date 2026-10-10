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
            'teams',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->ulid('parent_team_id')->nullable()->index();
                $table->foreignUlid('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->jsonb('ancestor_team_ids')->default('[]');
                $table->jsonb('descendant_team_ids')->default('[]');
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['tenant_id', 'slug'], 'unq_teams_tenant_slug');
            }
        );

        Schema::table(
            'teams',
            function (Blueprint $table): void {
                $table->foreign('parent_team_id')->references('id')->on('teams')->nullOnDelete();
            }
        );

        DB::statement('ALTER TABLE teams ADD CONSTRAINT chk_teams_parent_not_self CHECK (parent_team_id IS NULL OR parent_team_id <> id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
