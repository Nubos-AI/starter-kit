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
            'reminder_tasks',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('owner_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUlid('creator_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('record_id')->nullable()->constrained('custom_records')->cascadeOnDelete();
                $table->foreignUlid('reminder_type_id')->nullable()->constrained('reminder_types')->nullOnDelete();
                $table->timestamp('due_at')->nullable()->index();
                $table->string('subject');
                $table->text('note')->nullable();
                $table->timestamp('done_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'assignee_id', 'done_at', 'due_at'], 'idx_reminder_tasks_open');
                $table->index(['record_id'], 'idx_reminder_tasks_record');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_tasks');
    }
};
