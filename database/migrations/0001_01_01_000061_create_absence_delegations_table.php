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
            'absence_delegations',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('user_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('delegate_id')->index()->constrained('users')->cascadeOnDelete();
                $table
                    ->foreignUlid('created_by_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table
                    ->foreignUlid('updated_by_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table
                    ->foreignUlid('deleted_by_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at');
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_absence_delegations_active ON absence_delegations (tenant_id, user_id, starts_at, ends_at) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('absence_delegations');
    }
};
