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
            'configuration_artifact_versions',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->ulid('snapshot_group_id')->index();
                $table->string('artifact_kind');
                $table->string('artifact_key');
                $table->ulid('artifact_id')->nullable();
                $table->unsignedInteger('version_number');
                $table->jsonb('snapshot');
                $table->ulid('actor_id')->nullable()->index();
                $table->string('change_summary')->nullable();
                $table->timestamp('changed_at');

                $table->unique(['tenant_id', 'artifact_kind', 'artifact_key', 'version_number'], 'unq_cav_artifact_version');
                $table->index(['tenant_id', 'snapshot_group_id'], 'idx_cav_group');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_artifact_versions');
    }
};
