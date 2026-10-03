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
            'field_dependencies',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('rollup_field_id')
                    ->constrained('field_definitions')
                    ->cascadeOnDelete();
                $table
                    ->foreignUlid('depends_on_field_id')
                    ->index()
                    ->constrained('field_definitions')
                    ->cascadeOnDelete();
                $table->foreignUlid('relationship_type_id')
                    ->nullable()
                    ->constrained('relationship_types')
                    ->cascadeOnDelete();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_field_dependencies_edge ON field_dependencies (tenant_id, rollup_field_id, depends_on_field_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('field_dependencies');
    }
};
