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
            'import_mapping_presets',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('user_id')->nullable()->index()->constrained()->nullOnDelete();
                $table->string('name');
                $table->jsonb('mapping');
                $table->softDeletes();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mapping_presets');
    }
};
