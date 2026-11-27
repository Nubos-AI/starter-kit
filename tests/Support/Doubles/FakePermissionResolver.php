<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use App\Contracts\Authorization\PermissionHolderInterface;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Database\Eloquent\Model;

class FakePermissionResolver extends PermissionResolver
{
    /**
     * @var list<string>
     */
    public array $askedFor = [];

    /**
     * @param  list<string>  $granted
     */
    public function __construct(private array $granted = []) {}

    public function allows(PermissionHolderInterface $holder, string $ability, ?Model $scope = null): bool
    {
        $this->askedFor[] = $ability;

        return in_array($ability, $this->granted, true);
    }

    public function verdictFor(PermissionHolderInterface $holder, string $ability, ?Model $scope = null): ?bool
    {
        return $this->allows($holder, $ability, $scope);
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
