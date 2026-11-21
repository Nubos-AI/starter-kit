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
            'promotion_baselines',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->string('counterpart_key');
                $table->string('artifact_key');
                $table->string('hash');
                $table->timestamp('synced_at');
                $table->timestamps();

                $table->unique(['tenant_id', 'counterpart_key', 'artifact_key'], 'unq_promotion_baselines_entry');
                $table->index(['tenant_id', 'counterpart_key'], 'idx_promotion_baselines_counterpart');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_baselines');
    }
};
