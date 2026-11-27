<?php

declare(strict_types=1);

namespace App\Support\Temporal;

use Temporal\Exception\Failure\ApplicationFailure;
use Temporal\Exception\TemporalException;
use Temporal\Interceptor\ActivityInbound\ActivityInput;
use Temporal\Interceptor\ActivityInboundInterceptor;
use Temporal\Interceptor\Trait\ActivityInboundInterceptorTrait;
use Throwable;

class CompactActivityFailureInterceptor implements ActivityInboundInterceptor
{
    use ActivityInboundInterceptorTrait;

    public function __construct(private readonly ActivityFailures $failures) {}

    public function handleActivityInbound(ActivityInput $input, callable $next): mixed
    {
        try {
            return $next($input);
        } catch (Throwable $failure) {
            if ($failure instanceof TemporalException && !$failure instanceof ApplicationFailure) {
                throw $failure;
            }

            throw $this->failures->compact($failure, true);
        }
    }
}
