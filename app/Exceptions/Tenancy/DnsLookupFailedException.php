<?php

declare(strict_types=1);

namespace App\Exceptions\Tenancy;

use RuntimeException;
use Throwable;

class DnsLookupFailedException extends RuntimeException
{
    public function __construct(public readonly string $host, ?Throwable $previous = null)
    {
        parent::__construct("The name service could not be asked for {$host}.", 0, $previous);
    }
}
