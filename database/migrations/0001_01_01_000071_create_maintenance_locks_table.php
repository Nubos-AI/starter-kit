<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'maintenance_locks',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('acquired_by_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table
                    ->foreignUlid('released_by_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('reason');
                $table->string('status');
                $table->string('release_mode')->nullable();
                $table->jsonb('suspended_schedule_ids')->nullable();
                $table->text('note')->nullable();
                $table->text('release_note')->nullable();
                $table->timestamp('acquired_at');
                $table->timestamp('released_at')->nullable();
                $table->timestamps();
            }
        );

        DB::statement("CREATE UNIQUE INDEX unq_maintenance_locks_active ON maintenance_locks (tenant_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_locks');
    }
};
