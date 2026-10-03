<?php

declare(strict_types=1);

namespace App\DTOs\Routing;

use App\Enums\Routing\AssignmentFailureReason;
use App\Enums\Routing\AssignmentTarget;
use App\Models\User;

readonly class AssignmentOutcome
{
    public function __construct(
        public ?User $user,
        public bool $usedFallback,
        public ?AssignmentFailureReason $reason,
    ) {}

    public static function assigned(User $user, bool $usedFallback): self
    {
        return new self($user, $usedFallback, null);
    }

    public static function failed(AssignmentFailureReason $reason): self
    {
        return new self(null, false, $reason);
    }

    public function isAssigned(): bool
    {
        return $this->user instanceof User;
    }

    /**
     * @return array{target: string, assigned_user_id: string|null, used_fallback: bool, reason: string|null}
     */
    public function output(AssignmentTarget $target): array
    {
        return [
            'target' => $target->value,
            'assigned_user_id' => $this->user === null ? null : (string) $this->user->getKey(),
            'used_fallback' => $this->usedFallback,
            'reason' => $this->reason?->value,
        ];
    }
}
