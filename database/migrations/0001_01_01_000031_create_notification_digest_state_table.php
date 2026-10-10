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
            'notification_digest_state',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('user_id')
                    ->unique()
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->string('frequency')->nullable();
                $table->unsignedTinyInteger('hour');
                $table->timestamp('last_digest_sent_at')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_state');
    }
};
