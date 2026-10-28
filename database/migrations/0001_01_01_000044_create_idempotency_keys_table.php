<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table
                ->foreignId('access_token_id')
                ->index()
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->string('idempotency_key');
            $table->string('request_body_hash', 64);
            $table->string('status', 20);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('locked_at');
            $table->timestamp('created_at')->index();

            $table->unique(['tenant_id', 'access_token_id', 'idempotency_key'], 'uniq_idempotency_keys_claim');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
