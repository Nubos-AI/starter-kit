<?php

declare(strict_types=1);

namespace App\Enums\Users;

enum Salutation: string
{
    case Mister = 'mr';

    case Miss = 'ms';

    case Mix = 'mx';

    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Mister => __('i18n.backend.enums.users.salutation.mr'),
            self::Miss => __('i18n.backend.enums.users.salutation.ms'),
            self::Mix => __('i18n.backend.enums.users.salutation.other'),
            self::Unknown => __('i18n.backend.enums.users.salutation.not_specified'),
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->map(fn (self $salutation): array => [
                'value' => $salutation->value,
                'label' => $salutation->label(),
            ])
            ->all();
    }
}
