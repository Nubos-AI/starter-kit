<?php

declare(strict_types=1);

namespace App\Contracts\Links;

use App\DTOs\Links\SourceLinkData;
use App\Models\User;

interface SourceLinkResolverInterface
{
    public function key(): string;

    /**
     * @param  list<string>  $sourceIds
     * @return array<string, SourceLinkData>
     */
    public function resolve(User $user, array $sourceIds): array;

    public function isAccessible(User $user, string $sourceId): bool;
}
