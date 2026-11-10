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
            'field_definitions',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->constrained()->cascadeOnDelete();
                $table->ulid('field_group_id')->nullable()->index();
                $table->string('key');
                $table->string('field_type');
                $table->boolean('is_required')->default(false);
                $table->boolean('is_unique')->default(false);
                $table->boolean('is_searchable')->default(false);
                $table->boolean('is_translatable')->default(false);
                $table->boolean('is_encrypted')->default(false);
                $table->boolean('is_sortable')->default(false);
                $table->boolean('is_filterable')->default(false);
                $table->boolean('is_default_column')->default(false);
                $table->integer('list_position')->nullable();
                $table->string('merge_strategy')->nullable();
                $table->jsonb('config')->nullable();
                $table->jsonb('validation_rules')->nullable();
                $table->jsonb('default_value')->nullable();
                $table->jsonb('i18n_labels')->nullable();
                $table->jsonb('i18n_descriptions')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_field_definitions_type_key ON field_definitions (tenant_id, object_type_id, key) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
    }
};
