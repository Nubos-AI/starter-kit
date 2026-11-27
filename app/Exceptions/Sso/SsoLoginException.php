<?php

declare(strict_types=1);

namespace App\Exceptions\Sso;

use App\Enums\Sso\SsoFailure;
use RuntimeException;
use Throwable;

class SsoLoginException extends RuntimeException
{
    public function __construct(public readonly SsoFailure $failure, ?Throwable $previous = null)
    {
        parent::__construct("Single sign-on failed: {$failure->value}.", 0, $previous);
    }
}
