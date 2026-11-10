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
            'scim_tokens',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('created_by_id')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->string('token_hash', 64)->unique();
                $table->timestamp('last_used_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_scim_tokens_tenant ON scim_tokens (tenant_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_tokens');
    }
};
