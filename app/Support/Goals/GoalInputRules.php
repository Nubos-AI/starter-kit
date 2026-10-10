<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Enums\Engine\SystemFilterField;
use App\Enums\Goals\GoalDirection;
use App\Enums\Goals\GoalPeriodType;
use App\Enums\Goals\GoalScopeType;
use Illuminate\Validation\Rules\Enum;

class GoalInputRules
{
    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'report_id' => ['required', 'string'],
            'scope_type' => ['required', new Enum(GoalScopeType::class)],
            'target_user_id' => ['nullable', 'string'],
            'target_team_id' => ['nullable', 'string'],
            'includes_subteams' => ['nullable', 'boolean'],
            'scope_field_key' => ['nullable', 'string', 'max:255'],
            'period_field_key' => ['nullable', 'string', 'max:255'],
            'period_type' => ['required', new Enum(GoalPeriodType::class)],
            'direction' => ['required', new Enum(GoalDirection::class)],
            'target_value' => ['required', 'numeric'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function scopeType(array $validated): GoalScopeType
    {
        $scopeType = $validated['scope_type'] ?? null;

        return $scopeType instanceof GoalScopeType
            ? $scopeType
            : GoalScopeType::from((string) $scopeType);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function attributes(array $validated): array
    {
        $scopeType = self::scopeType($validated);

        return [
            'report_id' => $validated['report_id'],
            'target_user_id' => $scopeType === GoalScopeType::User ? $validated['target_user_id'] : null,
            'target_team_id' => $scopeType === GoalScopeType::Team ? $validated['target_team_id'] : null,
            'includes_subteams' => $scopeType === GoalScopeType::Team
                && (bool) ($validated['includes_subteams'] ?? false),
            'name' => $validated['name'],
            'scope_type' => $scopeType,
            'scope_field_key' => self::scopeFieldKey($validated, $scopeType),
            'period_field_key' => self::periodFieldKey($validated),
            'period_type' => $validated['period_type'],
            'direction' => $validated['direction'],
            'target_value' => $validated['target_value'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function periodFieldKey(array $validated): ?string
    {
        $chosen = $validated['period_field_key'] ?? null;

        return is_string($chosen) && $chosen !== '' ? $chosen : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function scopeFieldKey(array $validated, GoalScopeType $scopeType): ?string
    {
        $chosen = $validated['scope_field_key'] ?? null;

        if (is_string($chosen) && $chosen !== '') {
            return $chosen;
        }

        return match ($scopeType) {
            GoalScopeType::User => SystemFilterField::Owner->value,
            GoalScopeType::Team => SystemFilterField::Team->value,
            GoalScopeType::Tenant => null,
        };
    }
}
