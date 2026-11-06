<?php

declare(strict_types=1);

namespace App\Actions\Preferences;

use App\Enums\Preferences\PreferenceScope;
use App\Models\User;
use App\Models\UserPreference;
use App\Support\Preferences\PreferencePolicyResolver;
use App\Support\Preferences\PreferenceSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdateUserPreferencesAction
{
    public function __construct(
        private readonly PreferenceSchema $schema,
        private readonly PreferencePolicyResolver $policies,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(User $user, array $data): void
    {
        $validated = Validator::make($data, $this->schema->rules($data))->validate();

        $patch = $this->filter($user, $validated);

        if ($patch === [] || !is_string($user->tenant_id) || $user->tenant_id === '') {
            return;
        }

        DB::transaction(function () use ($user, $patch): void {
            $preference = UserPreference::query()
                ->forUser((string) $user->getKey())
                ->lockForUpdate()
                ->first() ?? new UserPreference([
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->getKey(),
                ]);

            $preference->settings = $this->merge(
                is_array($preference->settings) ? $preference->settings : [],
                $patch,
            );

            $preference->save();
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function filter(User $user, array $validated): array
    {
        $policy = $this->policies->forUser($user);
        $patch = [];

        foreach (PreferenceScope::cases() as $scope) {
            $slice = $this->scopeOf($validated, $scope);

            if ($slice === []) {
                continue;
            }

            $allowed = $scope->isKeyed()
                ? $this->filterKeyed($policy, $scope, $slice)
                : $this->policies->filterSlice($policy, $scope, $slice);

            if ($allowed !== []) {
                $patch[$scope->value] = $allowed;
            }
        }

        return $patch;
    }

    /**
     * @param  array<string, array<string, bool>>  $policy
     * @param  array<string, mixed>  $slice
     * @return array<string, mixed>
     */
    private function filterKeyed(array $policy, PreferenceScope $scope, array $slice): array
    {
        $allowed = [];

        foreach ($slice as $key => $values) {
            $filtered = $this->policies->filterSlice($policy, $scope, is_array($values) ? $values : []);

            if ($filtered !== []) {
                $allowed[$key] = $filtered;
            }
        }

        return $allowed;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function merge(array $stored, array $patch): array
    {
        foreach ($patch as $scopeKey => $slice) {
            $scope = PreferenceScope::from($scopeKey);
            $current = is_array($stored[$scopeKey] ?? null) ? $stored[$scopeKey] : [];

            if (!$scope->isKeyed()) {
                $stored[$scopeKey] = [...$current, ...$slice];

                continue;
            }

            foreach ($slice as $entryKey => $values) {
                $existing = is_array($current[$entryKey] ?? null) ? $current[$entryKey] : [];
                $current[$entryKey] = [...$existing, ...$values];
            }

            $stored[$scopeKey] = $current;
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function scopeOf(array $data, PreferenceScope $scope): array
    {
        $slice = $data[$scope->value] ?? [];

        return is_array($slice) ? $slice : [];
    }
}
