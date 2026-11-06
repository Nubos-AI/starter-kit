<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RelationDirection: string
{
    case Outgoing = 'outgoing';

    case Incoming = 'incoming';

    public function ownColumn(): string
    {
        return $this === self::Outgoing ? 'from_record_id' : 'to_record_id';
    }

    public function counterpartColumn(): string
    {
        return $this === self::Outgoing ? 'to_record_id' : 'from_record_id';
    }

    public function isOutgoing(): bool
    {
        return $this === self::Outgoing;
    }
}
