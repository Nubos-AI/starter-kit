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
            'activity_types',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->softDeletes();
                $table->timestamps();
            }
        );

        Schema::create('record_activities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignUlid('record_id')->constrained('custom_records')->cascadeOnDelete();
            $table->foreignUlid('activity_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('creator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject');
            $table->timestampTz('occurred_at');
            $table->text('result')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['tenant_id', 'record_id', 'occurred_at']);
        });

        DB::statement('CREATE UNIQUE INDEX unq_activity_types_tenant_name ON activity_types (tenant_id, name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('record_activities');
        Schema::dropIfExists('activity_types');
    }
};
