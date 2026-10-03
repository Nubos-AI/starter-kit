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
            'notification_rules',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->index()->constrained('object_types')->cascadeOnDelete();
                $table->string('name');
                $table->string('trigger_type');
                $table->jsonb('config')->nullable();
                $table->ulid('segment_id')->nullable();
                $table->jsonb('filter_definition')->nullable();
                $table->jsonb('action')->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignUlid('created_by_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();

                $table->index(['tenant_id', 'object_type_id', 'trigger_type', 'is_active'], 'idx_notification_rules_active');
            }
        );

        Schema::create(
            'notification_rule_dispatches',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('rule_id')->constrained('notification_rules')->cascadeOnDelete();
                $table->foreignUlid('record_id')->constrained('custom_records')->cascadeOnDelete();
                $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('stage');
                $table->timestamp('dispatched_at');

                $table->unique(['rule_id', 'record_id', 'user_id', 'stage'], 'uniq_notification_rule_dispatches');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rule_dispatches');
        Schema::dropIfExists('notification_rules');
    }
};
