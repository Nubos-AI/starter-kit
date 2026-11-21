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
            'relationship_types',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->string('key');
                $table->string('inverse_key');
                $table->string('name');
                $table->string('inverse_name');
                $table->foreignUlid('from_object_type_id')->constrained('object_types')->cascadeOnDelete();
                $table->foreignUlid('to_object_type_id')->constrained('object_types')->cascadeOnDelete();
                $table->string('cardinality');
                $table->boolean('is_required')->default(false);
                $table->boolean('is_hierarchy')->default(false);
                $table->string('cascade_behavior');
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_relationship_types_key ON relationship_types (tenant_id, key) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX unq_relationship_types_inverse_key ON relationship_types (tenant_id, inverse_key) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_types');
    }
};
