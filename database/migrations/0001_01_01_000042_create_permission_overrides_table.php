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
            'permission_overrides',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('permission_id')->constrained('permissions')->cascadeOnDelete();
                $table->ulidMorphs('model');
                $table->string('effect');
                $table->nullableUlidMorphs('scope');
                $table->timestamps();

                $table->unique(
                    ['tenant_id', 'model_type', 'model_id', 'permission_id', 'scope_type', 'scope_id'],
                    'unq_permission_override'
                );
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_overrides');
    }
};
