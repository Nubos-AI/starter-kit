<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class RunModuleMiddleware
{
    public function __construct(private readonly Pipeline $pipeline) {}

    public function handle(Request $request, Closure $next, string $point): Response
    {
        return $this->pipeline->send($request)->through(config("modules.middleware.{$point}", []))->then($next);
    }
}
