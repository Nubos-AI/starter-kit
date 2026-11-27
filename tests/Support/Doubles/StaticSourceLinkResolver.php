<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\Links\SourceLinkResolverInterface;
use App\DTOs\Links\SourceLinkData;
use App\Models\User;

class StaticSourceLinkResolver implements SourceLinkResolverInterface
{
    /**
     * @var list<list<string>>
     */
    public array $resolveCalls = [];

    /**
     * @var list<string>
     */
    public array $accessChecks = [];

    /**
     * @param  array<string, SourceLinkData>  $links
     * @param  list<string>  $accessible
     */
    public function __construct(
        private readonly string $sourceKey,
        private readonly array $links = [],
        private readonly array $accessible = [],
    ) {}

    public function key(): string
    {
        return $this->sourceKey;
    }

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, SourceLinkData>
     */
    public function resolve(User $user, array $sourceIds): array
    {
        $this->resolveCalls[] = $sourceIds;

        return array_intersect_key($this->links, array_flip($sourceIds));
    }

    public function isAccessible(User $user, string $sourceId): bool
    {
        $this->accessChecks[] = $sourceId;

        return in_array($sourceId, $this->accessible, true);
    }
}
