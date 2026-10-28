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
            'tenant_settings',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->unique()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('import_max_file_bytes');
                $table->unsignedInteger('import_max_rows');
                $table->unsignedInteger('bulk_grouping_threshold')->default(10);
                $table->time('quiet_hours_start')->nullable();
                $table->time('quiet_hours_end')->nullable();
                $table->jsonb('preference_policy')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
