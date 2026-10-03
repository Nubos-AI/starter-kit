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
            'segments',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('owner_id')->index()->constrained('users')->cascadeOnDelete();
                $table->string('name');
                $table->foreignUlid('object_type_id')->nullable()->index()->constrained()->cascadeOnDelete();
                $table->jsonb('filter_definition')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_default')->default(false);
                $table->jsonb('i18n_labels')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};
