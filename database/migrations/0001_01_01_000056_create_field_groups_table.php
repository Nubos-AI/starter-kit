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
            'field_groups',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->constrained()->cascadeOnDelete();
                $table->string('key');
                $table->jsonb('i18n_labels')->nullable();
                $table->jsonb('i18n_descriptions')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_field_groups_type_key ON field_groups (tenant_id, object_type_id, key) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_groups');
    }
};
