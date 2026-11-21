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
                $table->foreignUlid('series_id')->nullable()->index();
                $table->string('source_type')->nullable();
                $table->ulid('source_id')->nullable();
                $table->timestamp('due_at')->nullable()->index();
                $table->string('subject');
                $table->text('note')->nullable();
                $table->string('recurrence_frequency', 20)->nullable();
                $table->unsignedSmallInteger('recurrence_interval')->nullable();
                $table->timestamp('recurrence_ends_at')->nullable();
                $table->timestamp('recurrence_anchor_at')->nullable();
                $table->jsonb('recurrence_exceptions')->nullable();
                $table->timestamp('series_occurrence_at')->nullable();
                $table->timestamp('done_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->jsonb('notified_channels')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'assignee_id', 'done_at', 'due_at'], 'idx_reminder_tasks_open');
                $table->index(['record_id'], 'idx_reminder_tasks_record');
                $table->index(['tenant_id', 'source_type', 'source_id'], 'idx_reminder_tasks_tenant_id_source_type_source_id');
            }
        );

        Schema::table(
            'reminder_tasks',
            function (Blueprint $table): void {
                $table->foreign('series_id')->references('id')->on('reminder_tasks')->nullOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_tasks');
    }
};
