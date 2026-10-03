<?php

declare(strict_types=1);

namespace App\Http\Middleware\Authorization;

use App\Support\Authorization\RowAccess\RowAccessEnforcement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceRecordAccessRules
{
    public function __construct(private readonly RowAccessEnforcement $enforcement) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->enforcement->enable();

        return $next($request);
    }
}
