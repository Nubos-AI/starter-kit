<?php

declare(strict_types=1);

namespace App\DTOs\Approvals;

use App\Enums\Approvals\ApprovalExclusion;

readonly class ApprovalExclusionSet
{
    public function __construct(
        public bool $trigger,
        public bool $lastEditor,
        public bool $creator,
        public bool $owner,
    ) {}

    public static function strict(): self
    {
        return new self(trigger: true, lastEditor: true, creator: true, owner: true);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            trigger: self::isEnabled($config, ApprovalExclusion::Trigger),
            lastEditor: self::isEnabled($config, ApprovalExclusion::LastEditor),
            creator: self::isEnabled($config, ApprovalExclusion::Creator),
            owner: self::isEnabled($config, ApprovalExclusion::Owner),
        );
    }

    /**
     * @return array{trigger: bool, last_editor: bool, creator: bool, owner: bool}
     */
    public function toArray(): array
    {
        return [
            'trigger' => $this->trigger,
            'last_editor' => $this->lastEditor,
            'creator' => $this->creator,
            'owner' => $this->owner,
        ];
    }

    public function includes(ApprovalExclusion $reason): bool
    {
        return match ($reason) {
            ApprovalExclusion::Trigger => $this->trigger,
            ApprovalExclusion::LastEditor => $this->lastEditor,
            ApprovalExclusion::Creator => $this->creator,
            ApprovalExclusion::Owner => $this->owner,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function isEnabled(array $config, ApprovalExclusion $reason): bool
    {
        $key = $reason->value;

        return array_key_exists($key, $config) && $config[$key] !== null
            ? (bool) $config[$key]
            : true;
    }
}
