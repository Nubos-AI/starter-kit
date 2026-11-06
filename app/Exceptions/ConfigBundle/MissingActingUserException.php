<?php

declare(strict_types=1);

namespace App\Exceptions\ConfigBundle;

use RuntimeException;

class MissingActingUserException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}
