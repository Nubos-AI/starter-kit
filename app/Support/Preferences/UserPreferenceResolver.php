<?php

declare(strict_types=1);

namespace App\Support\Preferences;

use App\Enums\Preferences\PreferenceScope;
use App\Models\User;
use App\Models\UserPreference;

class UserPreferenceResolver
{
    public function __construct(
        private readonly PreferenceSchema $schema,
        private readonly PreferencePolicyResolver $policies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function document(User $user): array
    {
        $policy = $this->policies->forUser($user);

        return [
            ...$this->apply($this->stored($user), $policy),
            'policy' => $policy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sharedDocument(User $user): array
    {
        return [
            ...$this->document($user),
            PreferenceScope::ObjectTypes->value => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function objectTypeSlice(User $user, string $objectTypeId): array
    {
        $document = $this->document($user);

        return $document[PreferenceScope::ObjectTypes->value][$objectTypeId]
            ?? $this->schema->objectTypeDefaults();
    }

    /**
     * @return array<string, mixed>
     */
    public function stored(User $user): array
    {
        $preference = UserPreference::query()->forUser((string) $user->getKey())->first();

        return $preference instanceof UserPreference && is_array($preference->settings)
            ? $preference->settings
            : [];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, array<string, bool>>  $policy
     * @return array<string, mixed>
     */
    public function apply(array $stored, array $policy): array
    {
        $settings = $this->sliceOf($stored, PreferenceScope::Settings);

        return [
            PreferenceScope::Settings->value => [
                ...$this->schema->globalDefaults(),
                ...$this->policies->filterSlice($policy, PreferenceScope::Settings, $settings),
            ],
            PreferenceScope::ObjectTypes->value => $this->keyedScope(
                $stored,
                $policy,
                PreferenceScope::ObjectTypes,
                $this->schema->objectTypeDefaults(),
            ),
            PreferenceScope::Grids->value => $this->keyedScope(
                $stored,
                $policy,
                PreferenceScope::Grids,
                $this->schema->gridDefaults(),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, array<string, bool>>  $policy
     * @param  array<string, mixed>  $defaults
     * @return array<string, array<string, mixed>>
     */
    private function keyedScope(array $stored, array $policy, PreferenceScope $scope, array $defaults): array
    {
        $resolved = [];

        foreach ($this->sliceOf($stored, $scope) as $key => $slice) {
            $resolved[$key] = [
                ...$defaults,
                ...$this->policies->filterSlice($policy, $scope, is_array($slice) ? $slice : []),
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private function sliceOf(array $stored, PreferenceScope $scope): array
    {
        $slice = $stored[$scope->value] ?? [];

        return is_array($slice) ? $slice : [];
    }
}
