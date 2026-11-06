<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Models\User;
use App\Support\Authorization\ManagedUserResolver;

class FakeManagedUserResolver extends ManagedUserResolver
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @var list<string>
     */
    private array $managedUserIds = [];

    /**
     * @param  list<string>  $teams
     */
    private function __construct(private readonly bool $tenant, private readonly array $teams) {}

    public static function reachingTheTenant(): self
    {
        return self::install(new self(true, []));
    }

    /**
     * @param  list<string>  $teamIds
     */
    public static function reachingTeams(array $teamIds): self
    {
        return self::install(new self(false, $teamIds));
    }

    /**
     * @param  list<string>  $userIds
     */
    public function managing(array $userIds): self
    {
        $this->managedUserIds = $userIds;

        return $this;
    }

    public function manages(User $actingUser, User $target, string $ability): bool
    {
        $this->askedFor[] = $ability;

        return in_array((string) $target->getKey(), $this->managedUserIds, true);
    }

    /**
     * @return array{tenant: bool, teams: list<string>}
     */
    public function reachOf(User $actingUser, string $ability): array
    {
        $this->askedFor[] = $ability;

        return ['tenant' => $this->tenant, 'teams' => $this->teams];
    }

    private static function install(self $resolver): self
    {
        app()->instance(ManagedUserResolver::class, $resolver);

        return $resolver;
    }
}
