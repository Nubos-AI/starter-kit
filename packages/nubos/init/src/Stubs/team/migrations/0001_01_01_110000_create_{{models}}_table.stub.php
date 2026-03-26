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
            '{{models}}',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
                $table->string('slug')->unique();
                $table->boolean('personal_{{model}}')->default(false);
                // @nubos:inject-fields
                $table->string('name');
                $table->softDeletes();
                $table->timestamps();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('{{models}}');
    }
};
