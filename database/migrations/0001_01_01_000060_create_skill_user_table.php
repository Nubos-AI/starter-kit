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
            'skill_user',
            function (Blueprint $table): void {
                $table->foreignUlid('skill_id')->constrained('skills')->cascadeOnDelete();
                $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->primary(['skill_id', 'user_id']);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_user');
    }
};
