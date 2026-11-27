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
            'promotion_runs',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->ulid('source_tenant_id')->nullable()->index();
                $table->foreignUlid('triggered_by_id')->index()->constrained('users')->cascadeOnDelete();
                $table->ulid('snapshot_group_id')->nullable()->index();
                $table->string('counterpart_key');
                $table->string('direction');
                $table->string('status');
                $table->jsonb('selection');
                $table->jsonb('conflict_decisions');
                $table->jsonb('report')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_runs');
    }
};
