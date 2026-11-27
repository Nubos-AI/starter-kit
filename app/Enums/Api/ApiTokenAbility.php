<?php

declare(strict_types=1);

namespace App\Enums\Api;

enum ApiTokenAbility: string
{
    case RecordsRead = 'records:read';

    case RecordsWrite = 'records:write';

    public static function scope(): string
    {
        return 'records';
    }

    public static function forLevel(ApiAccessLevel $level): self
    {
        return match ($level) {
            ApiAccessLevel::Read => self::RecordsRead,
            ApiAccessLevel::Write => self::RecordsWrite,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::RecordsRead => __('i18n.backend.enums.api.api_token_ability.read_records'),
            self::RecordsWrite => __('i18n.backend.enums.api.api_token_ability.create_update_and_delete_records'),
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $ability): array => [
                'value' => $ability->value,
                'label' => $ability->label(),
            ])
            ->all();
    }
}
