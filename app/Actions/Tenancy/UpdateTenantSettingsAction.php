<?php

declare(strict_types=1);

namespace App\Actions\Tenancy;

use App\Enums\Preferences\PreferenceCategory;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

class UpdateTenantSettingsAction
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Throwable
     */
    public function execute(Tenant $tenant, array $data): TenantSetting
    {
        $validated = Validator::make($data, [
            'import_max_file_bytes' => ['sometimes', 'integer', 'min:1'],
            'import_max_rows' => ['sometimes', 'integer', 'min:1'],
            'bulk_grouping_threshold' => ['sometimes', 'integer', 'min:1'],
            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_end'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_with:quiet_hours_start'],
            ...$this->preferencePolicyRules($data['preference_policy'] ?? null),
        ])->validate();

        return DB::transaction(function () use ($tenant, $validated): TenantSetting {
            $settings = TenantSetting::forTenant($tenant->getKey());

            $settings->fill($validated);
            $settings->save();

            return $settings;
        });
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function preferencePolicyRules(mixed $policy): array
    {
        $rules = ['preference_policy' => ['sometimes', 'nullable', 'array']];

        foreach (PreferenceCategory::cases() as $category) {
            $rules["preference_policy.{$category->value}"] = ['sometimes', 'array'];

            foreach ($category->areas() as $area) {
                $rules["preference_policy.{$category->value}.{$area->value}"] = ['sometimes', 'boolean'];
            }
        }

        if (!is_array($policy)) {
            return $rules;
        }

        foreach ($policy as $categoryKey => $areas) {
            $category = is_string($categoryKey) ? PreferenceCategory::tryFrom($categoryKey) : null;

            if (!$category instanceof PreferenceCategory) {
                $rules["preference_policy.{$categoryKey}"] = ['prohibited'];

                continue;
            }

            $allowed = array_column($category->areas(), 'value');

            if (!is_array($areas)) {
                continue;
            }

            foreach (array_keys($areas) as $areaKey) {
                if (!in_array((string) $areaKey, $allowed, true)) {
                    $rules["preference_policy.{$category->value}.{$areaKey}"] = ['prohibited'];
                }
            }
        }

        return $rules;
    }
}
