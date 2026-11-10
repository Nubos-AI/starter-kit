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
            'routing_cursors',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->ulid('automation_id')->index();
                $table
                    ->foreignUlid('last_user_id')
                    ->nullable()
                    ->index()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->string('node_id');
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_routing_cursors_scope ON routing_cursors (tenant_id, automation_id, node_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_cursors');
    }
};
