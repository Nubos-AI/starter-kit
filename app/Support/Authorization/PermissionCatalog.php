<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Enums\Authorization\CrudAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeBackingRegistry;
use Illuminate\Support\Collection;

class PermissionCatalog
{
    public function __construct(private readonly ObjectTypeBackingRegistry $backings) {}

    /**
     * @return list<string>
     */
    public function objectTypeAbilities(): array
    {
        return [
            ...array_map(fn (CrudAction $action): string => $action->value, CrudAction::cases()),
            ...array_map(fn (ObjectTypeAbility $ability): string => $ability->value, ObjectTypeAbility::cases()),
        ];
    }

    /**
     * @return list<string>
     */
    public function globalNames(): array
    {
        $names = [];

        foreach ($this->globalGroups() as $group => $actions) {
            foreach ($actions as $action) {
                $names[] = "{$group}.{$action}";
            }
        }

        return $names;
    }

    /**
     * @return array<string, list<string>>
     */
    public function globalGroups(): array
    {
        $groups = config('permissions.groups', []);

        if (!is_array($groups)) {
            return [];
        }

        $result = [];

        foreach ($groups as $group => $meta) {
            if (!is_string($group) || !is_array($meta)) {
                continue;
            }

            $actions = $meta['actions'] ?? [];

            if (!is_array($actions)) {
                continue;
            }

            $result[$group] = array_values(array_filter($actions, static fn (mixed $action): bool => is_string($action)));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function namesForObjectType(ObjectType $objectType): array
    {
        try {
            $backing = $this->backings->for($objectType);
        } catch (MissingObjectTypeBackingException) {
            return array_map(
                fn (string $ability): string => "{$objectType->slug}.{$ability}",
                $this->objectTypeAbilities(),
            );
        }

        $names = [];

        foreach ([...CrudAction::cases(), ...ObjectTypeAbility::cases()] as $ability) {
            $name = $backing->permissionFor($objectType, $ability);

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  Collection<int, ObjectType>|null  $objectTypes
     * @return list<string>
     */
    public function all(?Collection $objectTypes = null): array
    {
        $names = $this->globalNames();

        foreach ($objectTypes ?? ObjectType::query()->get() as $objectType) {
            $names = [...$names, ...$this->namesForObjectType($objectType)];
        }

        return array_values(array_unique($names));
    }
}
