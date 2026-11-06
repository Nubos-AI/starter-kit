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
            'sso_connections',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table
                    ->foreignUlid('default_role_id')
                    ->nullable()
                    ->index()
                    ->constrained('roles')
                    ->nullOnDelete();
                $table->boolean('is_enabled')->default(false);
                $table->boolean('is_enforced')->default(false);
                $table->boolean('is_jit_enabled')->default(false);
                $table->string('issuer_url', 2048)->nullable();
                $table->string('client_id')->nullable();
                $table->text('client_secret')->nullable();
                $table->jsonb('allowed_domains')->default('[]');
                $table->jsonb('verified_domains')->default('[]');
                $table->timestamp('login_verified_at')->nullable();
                $table->string('login_verified_fingerprint', 64)->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_sso_connections_tenant ON sso_connections (tenant_id) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX idx_sso_connections_allowed_domains ON sso_connections USING gin (allowed_domains)');
        DB::statement('CREATE INDEX idx_sso_connections_verified_domains ON sso_connections USING gin (verified_domains)');
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_connections');
    }
};
