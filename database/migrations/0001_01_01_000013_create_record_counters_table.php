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
            'record_counters',
            function (Blueprint $table): void {
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('object_type_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('next_value')->default(0);
                $table->timestamps();

                $table->primary(['tenant_id', 'object_type_id'], 'pk_record_counters');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('record_counters');
    }
};
