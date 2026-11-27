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
            'record_links',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('relationship_type_id')->constrained()->cascadeOnDelete();
                $table->foreignUlid('from_record_type')->constrained('object_types')->cascadeOnDelete();
                $table->ulid('from_record_id');
                $table->foreignUlid('to_record_type')->constrained('object_types')->cascadeOnDelete();
                $table->ulid('to_record_id');
                $table->string('cardinality');
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_record_links_edge ON record_links (relationship_type_id, from_record_id, to_record_id)');
        DB::statement("CREATE UNIQUE INDEX unq_record_links_one_to_many ON record_links (relationship_type_id, to_record_id) WHERE cardinality = 'one_to_many'");
        DB::statement('CREATE INDEX idx_record_links_tenant_to ON record_links (tenant_id, to_record_id)');
        DB::statement('CREATE INDEX idx_record_links_tenant_from ON record_links (tenant_id, from_record_id)');
        DB::statement('CREATE INDEX idx_record_links_type_to ON record_links (relationship_type_id, to_record_id)');
        DB::statement('CREATE INDEX idx_record_links_type_from ON record_links (relationship_type_id, from_record_id)');
        DB::statement('CREATE INDEX idx_record_links_from_endpoint ON record_links (from_record_type, from_record_id)');
        DB::statement('CREATE INDEX idx_record_links_to_endpoint ON record_links (to_record_type, to_record_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('record_links');
    }
};
