<?php

declare(strict_types=1);

namespace App\Support\Engine;

class RecordChangeRelayRequest
{
    private bool $isPending = false;

    public function request(): void
    {
        $this->isPending = true;
    }

    public function take(): bool
    {
        $wasPending = $this->isPending;
        $this->isPending = false;

        return $wasPending;
    }

    public function discard(): void
    {
        $this->isPending = false;
    }
}
