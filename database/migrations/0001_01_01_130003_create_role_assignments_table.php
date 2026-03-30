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
            'role_assignments',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('role_id')->constrained()->cascadeOnDelete();
                $table->uuidMorphs('model');
                $table->nullableUuidMorphs('scope');
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['role_id', 'model_type', 'model_id', 'scope_type', 'scope_id']);
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
