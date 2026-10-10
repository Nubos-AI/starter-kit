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
            'approval_definition_stages',
            function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('approval_definition_id')->index()->constrained()->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->string('quorum_type');
                $table->unsignedInteger('quorum_count')->nullable();
                $table->unsignedInteger('deadline_hours')->nullable();
                $table->string('escalation_type')->nullable();
                $table->jsonb('candidate_sources');
                $table->jsonb('escalation_sources')->nullable();
                $table->softDeletes();
                $table->timestamps();
            }
        );

        DB::statement('CREATE UNIQUE INDEX unq_approval_definition_stages_position ON approval_definition_stages (approval_definition_id, position) WHERE deleted_at IS NULL');

        DB::statement("ALTER TABLE approval_definition_stages ADD CONSTRAINT chk_approval_definition_stages_quorum CHECK ((quorum_type = 'at_least_n' AND quorum_count IS NOT NULL AND quorum_count >= 1) OR (quorum_type IN ('any', 'all') AND quorum_count IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_definition_stages');
    }
};
