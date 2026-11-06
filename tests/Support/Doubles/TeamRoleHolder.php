<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Enums\Authorization\PermissionEffect;
use App\Models\Role;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tests\Support\ModelStub;

class TeamRoleHolder extends Team
{
    /**
     * @var Collection<int, Role>
     */
    private Collection $assignedRoles;

    /**
     * @var array<string, PermissionEffect>
     */
    private array $overrides = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Role>  $roles
     */
    public static function make(array $attributes = [], array $roles = [], string $seed = 'team'): self
    {
        /** @var self $holder */
        $holder = ModelStub::make(self::class, [
            'id' => ModelStub::ulid($seed),
            'ancestor_team_ids' => [],
            'descendant_team_ids' => [],
            ...$attributes,
        ]);

        $holder->assignedRoles = new Collection($roles);

        return $holder;
    }

    /**
     * @param  array<string, PermissionEffect>  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $this->overrides = $overrides;

        return $this;
    }

    /**
     * @return Collection<int, Role>
     */
    public function assignedRolesFor(?Model $scope = null): Collection
    {
        return $this->assignedRoles;
    }

    /**
     * @return array<string, PermissionEffect>
     */
    public function assignedOverridesFor(?Model $scope = null): array
    {
        return $this->overrides;
    }

    public function getMorphClass(): string
    {
        return Team::class;
    }

    public function getTable(): string
    {
        return 'teams';
    }
}
