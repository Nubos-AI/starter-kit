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
            'aging_rules',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('clock');
                $table->string('clock_field_key')->nullable();
                $table->jsonb('condition')->nullable();
                $table->jsonb('thresholds');
                $table->boolean('is_active')->default(true);
                $table->boolean('triggers_automation')->default(false);
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_aging_rules_type_name ON aging_rules (object_type_id, name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('aging_rules');
    }
};
