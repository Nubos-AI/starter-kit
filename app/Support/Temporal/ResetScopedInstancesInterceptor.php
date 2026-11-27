<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Illuminate\Contracts\Foundation\Application;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\ActivityInboundInterceptor;
use Temporal\Interceptor\Trait\ActivityInboundInterceptorTrait;

class ResetScopedInstancesInterceptor implements ActivityInboundInterceptor
{
    use ActivityInboundInterceptorTrait;

    public function __construct(private readonly Application $application) {}

    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        try {
            return $next($input);
        } finally {
            $this->application->forgetScopedInstances();
        }
    }
}
