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
            'user_identities',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('user_id')->index()->constrained()->cascadeOnDelete();
                $table->string('provider', 20);
                $table->string('issuer', 2048);
                $table->string('subject');
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_user_identities_tenant_provider_issuer_subject ON user_identities (tenant_id, provider, issuer, subject) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
