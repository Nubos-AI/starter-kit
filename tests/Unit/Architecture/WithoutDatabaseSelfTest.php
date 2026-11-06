<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\WithoutDatabase;
use Tests\TestCase;

uses(TestCase::class, WithoutDatabase::class);

it('leaves no configured database connection reachable for a database free test', function (): void {
    $connections = array_keys((array) config('database.connections'));

    expect($connections)->toContain((string) config('database.default'));

    $reachable = array_values(array_filter($connections, static function (string $connection): bool {
        try {
            DB::connection($connection)->getPdo();
        } catch (Throwable) {
            return false;
        }

        return true;
    }));

    expect($reachable)->toBe([]);
});
