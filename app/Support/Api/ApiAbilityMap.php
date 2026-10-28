<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\Api\ApiAccessLevel;
use App\Enums\Api\ApiTokenAbility;
use App\Enums\Authorization\CrudAction;
use App\Models\ObjectType;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

class ApiAbilityMap
{
    public function abilityFor(string $objectTypeSlug, ApiAccessLevel $level): string
    {
        return "{$objectTypeSlug}:{$level->value}";
    }

    /**
     * @param  list<array{objectType: string, levels: list<string>}>  $access
     * @return list<string>
     */
    public function abilitiesForAccess(array $access): array
    {
        $abilities = [];

        foreach ($access as $entry) {
            foreach (ApiAccessLevel::cases() as $level) {
                if (in_array($level->value, $entry['levels'], true)) {
                    $abilities[] = $this->abilityFor($entry['objectType'], $level);
                }
            }
        }

        return array_values(array_unique($abilities));
    }

    /**
     * @param  list<string>  $abilities
     */
    public function satisfies(array $abilities, ?string $objectTypeSlug, ApiAccessLevel $level): bool
    {
        if (in_array(ApiTokenAbility::forLevel($level)->value, $abilities, true)) {
            return true;
        }

        if ($objectTypeSlug !== null) {
            return in_array($this->abilityFor($objectTypeSlug, $level), $abilities, true);
        }

        return collect($abilities)->contains(
            fn (string $ability): bool => Str::endsWith($ability, ":{$level->value}"),
        );
    }

    public function satisfiesObjectType(mixed $token, ?ObjectType $objectType, ApiAccessLevel $level): bool
    {
        if (!$token instanceof PersonalAccessToken || !$objectType instanceof ObjectType) {
            return false;
        }

        return $this->satisfies(array_values($token->abilities ?? []), $objectType->slug, $level);
    }

    /**
     * @param  list<string>  $abilities
     * @return array<string, true>
     */
    public function grantsFor(array $abilities): array
    {
        $grants = [];

        foreach ($abilities as $ability) {
            $slug = Str::beforeLast($ability, ':');
            $level = ApiAccessLevel::tryFrom(Str::afterLast($ability, ':'));

            if ($level === null || $slug === '' || $slug === ApiTokenAbility::scope()) {
                continue;
            }

            foreach ($this->permissionsFor($slug, $level) as $permission) {
                $grants[$permission] = true;
            }
        }

        return $grants;
    }

    /**
     * @return list<string>
     */
    public function permissionsFor(string $objectTypeSlug, ApiAccessLevel $level): array
    {
        return array_map(
            static fn (CrudAction $action): string => "{$objectTypeSlug}.{$action->value}",
            $this->actionsFor($level),
        );
    }

    /**
     * @return list<CrudAction>
     */
    private function actionsFor(ApiAccessLevel $level): array
    {
        return match ($level) {
            ApiAccessLevel::Read => [CrudAction::View],
            ApiAccessLevel::Write => [CrudAction::Create, CrudAction::Update, CrudAction::Delete],
        };
    }
}
