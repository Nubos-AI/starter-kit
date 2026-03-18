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
            'tenant_user',
            function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('tenant_id')
                    ->index('idx_tenant_user_tenant_id')
                    ->constrained()
                    ->cascadeOnDelete();
                $table->foreignUuid('user_id')
                    ->index('idx_tenant_user_user_id')
                    ->constrained()
                    ->cascadeOnDelete();
                $table->string('role', 50)->default('member');
                $table->timestamps();

                $table->unique(['tenant_id', 'user_id'], 'uniq_tenant_user');
            },
        );
    }
};
