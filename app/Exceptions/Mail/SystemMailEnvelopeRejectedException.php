<?php

declare(strict_types=1);

namespace App\Exceptions\Mail;

use RuntimeException;

class SystemMailEnvelopeRejectedException extends RuntimeException
{
    public static function forClass(string $deliveryKey, string $class): self
    {
        return new self("The sealed system mail \"{$deliveryKey}\" carries the class \"{$class}\", which a mail envelope may not hold.");
    }
}
