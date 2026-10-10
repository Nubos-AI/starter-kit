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
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create(
            'custom_records',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('team_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignUlid('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('object_type_id')->constrained()->cascadeOnDelete();
                $table->ulid('merged_into_record_id')->nullable()->index();
                $table->string('record_number')->nullable();
                $table->string('external_reference_id')->nullable();
                $table->string('deletion_reason')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->jsonb('data')->nullable();
                $table->timestamp('merged_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_custom_records_data_gin ON custom_records USING gin (data jsonb_path_ops)');
        DB::statement('CREATE UNIQUE INDEX unq_custom_records_tenant_number ON custom_records (tenant_id, record_number) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX unq_custom_records_tenant_extref ON custom_records (tenant_id, external_reference_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_custom_records_tenant_type_created ON custom_records (tenant_id, object_type_id, created_at) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_custom_records_tenant_created_id ON custom_records (tenant_id, created_at, id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_custom_records_deleted_at ON custom_records (deleted_at) WHERE deleted_at IS NOT NULL');
        DB::statement('CREATE INDEX idx_custom_records_tenant_owner ON custom_records (tenant_id, owner_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_custom_records_record_number_trgm ON custom_records USING gin (record_number gin_trgm_ops) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_custom_records_extref_trgm ON custom_records USING gin (external_reference_id gin_trgm_ops) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_records');
    }
};
