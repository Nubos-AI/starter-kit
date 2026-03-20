<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Numbering: 110000-110001 = base org, 110002-110003 = sub-teams (P3), 110004-110005 = user table extensions

    public function up(): void
    {
        Schema::create('{{models}}', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // @nubos:inject-fields
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('personal_{{model}}')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{models}}');
    }
};
