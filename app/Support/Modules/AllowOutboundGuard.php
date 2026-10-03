<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Contracts\Modules\OutboundGuardInterface;
use App\Enums\Notifications\SuppressedChannel;
use App\Exceptions\Modules\OutboundBlockedException;

class AllowOutboundGuard implements OutboundGuardInterface
{
    public function exceptionFor(string $target): OutboundBlockedException
    {
        return new OutboundBlockedException($target);
    }

    public function isOutboundBlocked(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $payload */
    public function suppressIfBlocked(SuppressedChannel $channel, string $reason, ?string $recipient = null, ?string $subject = null, array $payload = []): bool
    {
        return false;
    }
}
