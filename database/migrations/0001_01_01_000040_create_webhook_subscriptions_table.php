<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('target_url');
            $table->string('auth_username')->nullable();
            $table->string('auth_password', 500)->nullable();
            $table->text('secret');
            $table->text('secret_previous')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->string('status');
            $table->foreignUlid('service_user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->foreignUlid('role_id')->nullable()->index()->constrained('roles')->nullOnDelete();
            $table->jsonb('event_types');
            $table
                ->foreignUlid('object_type_id')
                ->nullable()
                ->index()
                ->constrained('object_types')
                ->cascadeOnDelete();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
