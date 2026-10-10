<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use Closure;

class RowAccessEnforcement
{
    private int $suspensions = 0;

    private bool $enabled = false;

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->suspensions === 0;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function enforcing(Closure $callback): mixed
    {
        $wasEnabled = $this->enabled;

        $this->enabled = true;

        try {
            return $callback();
        } finally {
            $this->enabled = $wasEnabled;
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutEnforcement(Closure $callback): mixed
    {
        $this->suspensions++;

        try {
            return $callback();
        } finally {
            $this->suspensions--;
        }
    }
}
