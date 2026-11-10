<?php

declare(strict_types=1);

namespace App\DTOs\Scim;

readonly class ScimGroupChanges
{
    /**
     * @param  list<string>|null  $replaceMembers
     * @param  list<string>  $addMembers
     * @param  list<string>  $removeMembers
     */
    public function __construct(
        public ?string $displayName,
        public ?array $replaceMembers,
        public array $addMembers,
        public array $removeMembers,
    ) {}

    public function changesMembers(): bool
    {
        return $this->replaceMembers !== null || $this->addMembers !== [] || $this->removeMembers !== [];
    }
}
