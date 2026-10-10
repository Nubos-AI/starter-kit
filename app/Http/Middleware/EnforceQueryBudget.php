<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnforceQueryBudget
{
    private string $supportedDriver = 'pgsql';

    public function handle(Request $request, Closure $next): Response
    {
        $budget = (int) config('database.statement_timeout_ms');

        if ($budget <= 0 || DB::connection()->getDriverName() !== $this->supportedDriver) {
            return $next($request);
        }

        DB::statement(sprintf('SET statement_timeout = %d', $budget));

        try {
            return $next($request);
        } finally {
            DB::statement('SET statement_timeout = DEFAULT');
        }
    }
}
