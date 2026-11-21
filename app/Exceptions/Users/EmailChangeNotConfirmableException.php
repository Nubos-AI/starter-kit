<?php

declare(strict_types=1);

namespace App\Exceptions\Users;

use RuntimeException;

class EmailChangeNotConfirmableException extends RuntimeException
{
    public static function noOpenRequest(): self
    {
        return new self('The email address change has no open request for this token or it expired.');
    }
}
