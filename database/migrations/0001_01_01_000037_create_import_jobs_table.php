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
            'import_jobs',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('user_id')->index()->constrained()->cascadeOnDelete();
                $table->string('status')->default('pending');
                $table->string('original_filename');
                $table->string('source_path');
                $table->string('source_disk');
                $table->string('format');
                $table->jsonb('mapping');
                $table->string('duplicate_mode');
                $table->string('missing_option_mode')->default('error');
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('created_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('error_count')->default(0);
                $table->string('error_report_path')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
