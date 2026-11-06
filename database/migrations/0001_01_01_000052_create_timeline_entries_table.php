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
            'timeline_entries',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('record_id')->index()->constrained('custom_records')->cascadeOnDelete();
                $table->string('source_key');
                $table->ulid('source_id')->nullable();
                $table->ulid('actor_id')->nullable();
                $table->string('actor_type')->nullable();
                $table->string('channel')->nullable();
                $table->timestamp('occurred_at');
                $table->jsonb('payload')->nullable();
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_timeline_entries_record_cursor ON timeline_entries (tenant_id, record_id, occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX idx_timeline_entries_record_source ON timeline_entries (tenant_id, record_id, source_key, occurred_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('timeline_entries');
    }
};
