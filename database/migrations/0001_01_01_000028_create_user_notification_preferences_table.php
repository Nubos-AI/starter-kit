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
            'user_notification_preferences',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('user_id')
                    ->index()
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->string('type');
                $table->string('channel');
                $table->boolean('enabled')->default(true);
                $table->string('delivery_mode');
                $table->timestamps();

                $table->unique(['user_id', 'type', 'channel'], 'unq_user_notification_preferences');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
    }
};
