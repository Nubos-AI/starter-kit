<?php

declare(strict_types=1);

namespace App\Exceptions\Outbox;

use InvalidArgumentException;

class ReservedOutboxTopicException extends InvalidArgumentException
{
    public function __construct(public readonly string $topic)
    {
        parent::__construct("The outbox topic [{$topic}] is reserved for record changes written by the audit recorder.");
    }
}
