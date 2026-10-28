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
            'field_permissions',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('role_id')->constrained('roles')->cascadeOnDelete();
                $table
                    ->foreignUlid('field_definition_id')
                    ->index()
                    ->constrained('field_definitions')
                    ->cascadeOnDelete();
                $table->boolean('can_read')->default(false);
                $table->boolean('can_write')->default(false);
                $table->timestamps();

                $table->unique(['tenant_id', 'role_id', 'field_definition_id'], 'unq_field_permission');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('field_permissions');
    }
};
