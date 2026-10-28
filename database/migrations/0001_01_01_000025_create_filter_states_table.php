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
            'filter_states',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('created_by')->constrained('users')->cascadeOnDelete();
                $table->jsonb('tree');
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('filter_states');
    }
};
