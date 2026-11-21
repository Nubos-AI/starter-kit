<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX unq_approval_events_principal ON approval_events (tenant_id, approval_process_stage_id, COALESCE(on_behalf_of_id, actor_id)) WHERE type IN ('approved', 'rejected')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS unq_approval_events_principal');
    }
};
