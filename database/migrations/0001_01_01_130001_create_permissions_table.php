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
            'permissions',
            function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->string('group')->nullable();
                $table->string('scope');
                $table->boolean('is_system')->default(true);
                $table->softDeletes();
                $table->timestamps();

                $table->unique(['name', 'scope']);
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
