<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routing_cursors', function (Blueprint $table): void {
            $table->renameColumn('automation_id', 'scope_id');
            $table->renameIndex('routing_cursors_automation_id_index', 'routing_cursors_scope_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('routing_cursors', function (Blueprint $table): void {
            $table->renameIndex('routing_cursors_scope_id_index', 'routing_cursors_automation_id_index');
            $table->renameColumn('scope_id', 'automation_id');
        });
    }
};
