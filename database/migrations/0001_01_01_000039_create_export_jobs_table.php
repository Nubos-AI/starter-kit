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
            'export_jobs',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('user_id')->index()->constrained()->cascadeOnDelete();
                $table->string('status')->default('pending');
                $table->string('format');
                $table->jsonb('scope');
                $table->jsonb('fields');
                $table->string('result_path')->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
