<?php

declare(strict_types=1);

namespace App\Exceptions\Outbox;

use RuntimeException;

class OutboxClaimLostException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The outbox batch is no longer owned by this relay execution.');
    }
}
