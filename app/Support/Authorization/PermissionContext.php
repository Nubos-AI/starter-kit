<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\PermissionEffect;
use Closure;

class PermissionContext
{
    private ?self $team = null;

    private bool $teamResolved = false;

    /**
     * @param  array<string, PermissionEffect>  $overrides
     * @param  array<string, true>  $grants
     * @param  (Closure():?self)|null  $teamProvider
     */
    public function __construct(
        private readonly bool $escalated,
        private readonly array $overrides,
        private readonly array $grants,
        private readonly ?Closure $teamProvider = null,
    ) {}

    public function ownVerdict(string $ability): ?bool
    {
        if ($this->escalated) {
            return true;
        }

        if (isset($this->overrides[$ability])) {
            return $this->overrides[$ability] === PermissionEffect::Allow;
        }

        return isset($this->grants[$ability]) ? true : null;
    }

    public function allows(string $ability): bool
    {
        $own = $this->ownVerdict($ability);

        if ($own !== null) {
            return $own;
        }

        return $this->teamContext()?->ownVerdict($ability) ?? false;
    }

    private function teamContext(): ?self
    {
        if (!$this->teamResolved) {
            $this->teamResolved = true;
            $this->team = $this->teamProvider === null ? null : ($this->teamProvider)();
        }

        return $this->team;
    }
}
