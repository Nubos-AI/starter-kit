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
        Schema::create('object_type_definition_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('object_type_id', 26);
            $table->ulid('snapshot_group_id')->nullable()->index();
            $table->unsignedInteger('version_number');
            $table->jsonb('snapshot');
            $table->char('actor_id', 26)->nullable();
            $table->string('change_summary')->nullable();
            $table->timestamp('changed_at');

            $table->unique(['object_type_id', 'version_number'], 'unq_otdv_type_version');
        });

        Schema::create('field_definition_versions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('object_type_definition_version_id', 26)->index();
            $table->char('object_type_id', 26);
            $table->char('field_definition_id', 26)->nullable()->index();
            $table->ulid('snapshot_group_id')->nullable()->index();
            $table->string('field_key');
            $table->jsonb('snapshot');
            $table->unsignedInteger('version_number');
            $table->timestamp('changed_at');
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION definition_versions_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'definition versions are append-only: % is not permitted', TG_OP;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_otdv_append_only
                BEFORE UPDATE OR DELETE ON object_type_definition_versions
                FOR EACH ROW EXECUTE FUNCTION definition_versions_reject_mutation()
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_fdv_append_only
                BEFORE UPDATE OR DELETE ON field_definition_versions
                FOR EACH ROW EXECUTE FUNCTION definition_versions_reject_mutation()
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_fdv_append_only ON field_definition_versions');
        DB::statement('DROP TRIGGER IF EXISTS trg_otdv_append_only ON object_type_definition_versions');
        Schema::dropIfExists('field_definition_versions');
        Schema::dropIfExists('object_type_definition_versions');
        DB::statement('DROP FUNCTION IF EXISTS definition_versions_reject_mutation()');
    }
};
