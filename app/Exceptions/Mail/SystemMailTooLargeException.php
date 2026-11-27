<?php

declare(strict_types=1);

namespace App\Exceptions\Mail;

use RuntimeException;

class SystemMailTooLargeException extends RuntimeException
{
    public static function make(string $mailClass, int $bytes, int $limit): self
    {
        return new self("The system mail \"{$mailClass}\" carries {$bytes} bytes, more than the {$limit} bytes a workflow payload may hold.");
    }
}
