<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Database\Eloquent\Model;

class ScopedPermissionResolver extends PermissionResolver
{
    /**
     * @var list<array{ability: string, scope: string}>
     */
    public array $askedFor = [];

    /**
     * @param  array<string, list<string>>  $abilitiesByScope
     */
    public function __construct(private readonly array $abilitiesByScope = []) {}

    /**
     * @param  array<string, list<string>>  $abilitiesByScope
     */
    public static function install(array $abilitiesByScope): self
    {
        $resolver = new self($abilitiesByScope);

        app()->instance(PermissionResolver::class, $resolver);

        return $resolver;
    }

    public function allows(PermissionHolderInterface $holder, string $ability, ?Model $scope = null): bool
    {
        return $this->verdictFor($holder, $ability, $scope) === true;
    }

    public function verdictFor(PermissionHolderInterface $holder, string $ability, ?Model $scope = null): ?bool
    {
        $key = $scope === null ? '' : (string) $scope->getKey();

        $this->askedFor[] = ['ability' => $ability, 'scope' => $key];

        return in_array($ability, $this->abilitiesByScope[$key] ?? [], true);
    }

    /**
     * @param  list<string>  $abilities
     * @return array<string, bool>
     */
    public function map(PermissionHolderInterface $holder, array $abilities, ?Model $scope = null): array
    {
        $resolved = [];

        foreach ($abilities as $ability) {
            $resolved[$ability] = $this->allows($holder, $ability, $scope);
        }

        return $resolved;
    }
}
