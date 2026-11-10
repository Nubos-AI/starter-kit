<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'roles',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('scope');
                $table->string('authority')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('grants_subteam_visibility')->default(false);
                $table->timestamps();

                $table->unique(['tenant_id', 'name', 'scope'], 'unq_role_name_scope');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
