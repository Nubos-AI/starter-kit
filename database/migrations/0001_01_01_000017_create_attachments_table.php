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
            'attachments',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('record_id')->constrained('custom_records')->cascadeOnDelete();
                $table->string('field_key')->nullable();
                $table->string('original_name');
                $table->string('mime');
                $table->unsignedBigInteger('size');
                $table->string('disk');
                $table->string('path');
                $table->foreignUlid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->softDeletes();
                $table->timestamps();

                $table->index(['tenant_id', 'record_id', 'field_key']);
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
