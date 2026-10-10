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
            'team_record_access_rules',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('team_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('created_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->string('inheritance')->default('intersect');
                $table->jsonb('filter_definition');
                $table->softDeletes();
                $table->timestamps();

                $table->index(['tenant_id', 'object_type_id'], 'idx_team_record_access_rules_tenant_object_type');
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_team_record_access_rules_team_object_type ON team_record_access_rules (team_id, object_type_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('team_record_access_rules');
    }
};
