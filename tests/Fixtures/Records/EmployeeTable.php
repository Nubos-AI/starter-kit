<?php

declare(strict_types=1);

namespace Tests\Fixtures\Records;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EmployeeTable
{
    public static function create(): void
    {
        self::drop();

        Schema::create('employees', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('manager_id')->nullable();
            $table->string('full_name');
            $table->string('internal_note')->nullable();
            $table->string('secret_token')->nullable();
            $table->integer('headcount')->nullable();
            $table->timestamps();
        });

        config()->set('engine.model_paths', []);
        config()->set('engine.native_backings', ['employees' => Employee::class]);
    }

    public static function drop(): void
    {
        Schema::dropIfExists('employees');
    }
}
