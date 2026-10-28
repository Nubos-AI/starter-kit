<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Authorization\RowAccess\TeamChainProvider;

class StaticTeamChainProvider extends TeamChainProvider
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  list<list<string>>  $chains
     */
    public function __construct(private array $chains = []) {}

    /**
     * @return list<list<string>>
     */
    public function chainsFor(User $user): array
    {
        $this->askedFor[] = (string) $user->getKey();

        return $this->chains;
    }
}
