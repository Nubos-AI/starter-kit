<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_events', fn (Blueprint $table) => $table->renameColumn('triggered_by_automation_id', 'triggered_by_actor_id'));
    }

    public function down(): void
    {
        Schema::table('outbox_events', fn (Blueprint $table) => $table->renameColumn('triggered_by_actor_id', 'triggered_by_automation_id'));
    }
};
