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
            'tenants',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('domain', 253)->nullable();
                $table->timestamp('domain_verified_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX idx_tenants_domain ON tenants (domain) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
