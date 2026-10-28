<?php

declare(strict_types=1);

namespace App\Enums\Outbox;

enum OutboxTopic: string
{
    case RecordChanged = 'record.changed';
}
