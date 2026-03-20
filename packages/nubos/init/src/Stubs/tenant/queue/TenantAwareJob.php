<?php

declare(strict_types=1);

namespace App\Queue\Middleware;

use Closure;

class TenantAwareJob
{
    public function handle(object $job, Closure $next): void
    {
        if (method_exists($job, 'restoreTenantContext')) {
            $job->restoreTenantContext();
        }

        $next($job);
    }
}
