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
            'object_types',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->ulid('hierarchy_relationship_type_id')->nullable()->index();
                $table->string('key');
                $table->string('slug');
                $table->string('business_key_prefix', 4)->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('requires_deletion_reason')->default(false);
                $table->boolean('is_navigable')->default(true);
                $table->string('storage_strategy')->default('generic');
                $table->string('name');
                $table->string('nav_icon')->default('database');
                $table->unsignedSmallInteger('nav_position')->default(0);
                $table->string('record_number_format')->nullable();
                $table->unsignedSmallInteger('retention_days')->nullable();
                $table->jsonb('dedup_keys')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_object_types_slug ON object_types (tenant_id, slug) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX unq_object_types_key ON object_types (tenant_id, key) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX unq_object_types_business_key_prefix ON object_types (tenant_id, business_key_prefix) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('object_types');
    }
};
