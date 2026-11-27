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
            'notification_type_defaults',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->string('type');
                $table->string('channel');
                $table->boolean('enabled')->default(true);
                $table->string('delivery_mode');
                $table->timestamps();

                $table->unique(['tenant_id', 'type', 'channel'], 'unq_notification_type_defaults');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_type_defaults');
    }
};
