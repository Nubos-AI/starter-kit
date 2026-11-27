<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Enums\Authorization\PermissionEffect;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\ScopeResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tests\Support\ModelStub;

class RoleHolder extends User
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
     * @var array<string, list<Role>>
     */
    private array $rolesByScope = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<Role>  $roles
     */
    public static function make(array $attributes = [], array $roles = [], string $seed = 'actor'): self
    {
        /** @var self $holder */
        $holder = ModelStub::make(self::class, ['id' => ModelStub::ulid($seed), ...$attributes]);
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
     * @param  list<Role>  $roles
     */
    public function withRolesIn(Model $scope, array $roles): self
    {
        $this->rolesByScope[ScopeResolver::signatureFor($scope)] = $roles;

        return $this;
    }

    /**
     * @return Collection<int, Role>
     */
    public function assignedRolesFor(?Model $scope = null): Collection
    {
        if ($this->rolesByScope === []) {
            return $this->assignedRoles;
        }

        $reached = app(ScopeResolver::class)
            ->resolve($scope, $this)
            ->flatMap(fn (Model $candidate): array => $this->rolesByScope[ScopeResolver::signatureFor($candidate)] ?? []);

        return $this->assignedRoles->merge($reached)->values();
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
        return User::class;
    }

    public function getTable(): string
    {
        return 'users';
    }
}
