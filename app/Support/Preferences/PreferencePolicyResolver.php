<?php

declare(strict_types=1);

namespace App\Support\Preferences;

use App\Enums\Preferences\GlobalPreference;
use App\Enums\Preferences\GridPreference;
use App\Enums\Preferences\ObjectTypePreference;
use App\Enums\Preferences\PreferenceArea;
use App\Enums\Preferences\PreferenceCategory;
use App\Enums\Preferences\PreferenceScope;
use App\Models\TenantSetting;
use App\Models\User;

class PreferencePolicyResolver
{
    /**
     * @return array<string, array<string, bool>>
     */
    public function forUser(User $user): array
    {
        $tenantId = $user->tenant_id;

        return is_string($tenantId) && $tenantId !== ''
            ? $this->forTenant($tenantId)
            : $this->withDefaults([]);
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function forTenant(string $tenantId): array
    {
        $stored = TenantSetting::forTenant($tenantId)->preference_policy;

        return $this->withDefaults(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, array<string, bool>>
     */
    public function withDefaults(array $stored): array
    {
        $policy = [];

        foreach (PreferenceCategory::cases() as $category) {
            $current = $stored[$category->value] ?? [];

            foreach ($category->areas() as $area) {
                $policy[$category->value][$area->value] = is_array($current) && array_key_exists($area->value, $current)
                    ? (bool) $current[$area->value]
                    : $category->defaultEnabled();
            }
        }

        return $policy;
    }

    /**
     * @param  array<string, array<string, bool>>  $policy
     */
    public function allows(array $policy, PreferenceCategory $category, PreferenceArea $area): bool
    {
        return $policy[$category->value][$area->value] ?? $category->defaultEnabled();
    }

    /**
     * @param  array<string, array<string, bool>>  $policy
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function filterSlice(array $policy, PreferenceScope $scope, array $values): array
    {
        $area = $scope->area();

        return array_filter(
            $values,
            fn (string $key): bool => $this->allows($policy, $this->categoryOf($scope, $key), $area),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function categoryOf(PreferenceScope $scope, string $key): PreferenceCategory
    {
        return match ($scope) {
            PreferenceScope::Settings => GlobalPreference::from($key)->category(),
            PreferenceScope::ObjectTypes => ObjectTypePreference::from($key)->category(),
            PreferenceScope::Grids => GridPreference::from($key)->category(),
        };
    }
}
