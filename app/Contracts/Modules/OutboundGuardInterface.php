<?php

declare(strict_types=1);

namespace App\Contracts\Modules;

use App\Enums\Notifications\SuppressedChannel;
use App\Exceptions\Modules\OutboundBlockedException;

interface OutboundGuardInterface
{
    public function exceptionFor(string $target): OutboundBlockedException;

    public function isOutboundBlocked(): bool;

    /** @param array<string, mixed> $payload */
    public function suppressIfBlocked(SuppressedChannel $channel, string $reason, ?string $recipient = null, ?string $subject = null, array $payload = []): bool;
}
