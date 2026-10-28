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
            'record_notes',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('record_id')->index()->constrained('custom_records')->cascadeOnDelete();
                $table
                    ->foreignUlid('author_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->text('body');
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE INDEX idx_record_notes_tenant_record ON record_notes (tenant_id, record_id, created_at DESC) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('record_notes');
    }
};
