<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(
            'object_types',
            function (Blueprint $table): void {
                $table->jsonb('i18n_labels')->nullable();
            }
        );
    }

    public function down(): void
    {
        Schema::table(
            'object_types',
            function (Blueprint $table): void {
                $table->dropColumn('i18n_labels');
            }
        );
    }
};
