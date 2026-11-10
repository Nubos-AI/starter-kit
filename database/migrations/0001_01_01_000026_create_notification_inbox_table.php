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
            'notification_inbox',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('user_id')
                    ->index()
                    ->constrained('users')
                    ->cascadeOnDelete();
                $table->string('type');
                $table->jsonb('data')->nullable();
                $table->unsignedTinyInteger('priority')->default(0);
                $table->timestamp('read_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'user_id', 'archived_at', 'read_at'], 'idx_notification_inbox_scope');
                $table->index(['user_id', 'snoozed_until'], 'idx_notification_inbox_snooze');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_inbox');
    }
};
