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
            'role_permission',
            function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('role_id')->index()->constrained('roles')->cascadeOnDelete();
                $table->foreignUlid('permission_id')->index()->constrained('permissions')->cascadeOnDelete();

                $table->primary(['tenant_id', 'role_id', 'permission_id']);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission');
    }
};
