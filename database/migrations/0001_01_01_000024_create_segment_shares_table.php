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
            'segment_shares',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->index()->constrained()->cascadeOnDelete();
                $table->foreignUlid('segment_id')->constrained()->cascadeOnDelete();
                $table->ulidMorphs('grantee');
                $table->boolean('can_edit')->default(false);
                $table->foreignUlid('granted_by')->nullable()->index()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['segment_id', 'grantee_type', 'grantee_id'], 'unq_segment_share');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('segment_shares');
    }
};
