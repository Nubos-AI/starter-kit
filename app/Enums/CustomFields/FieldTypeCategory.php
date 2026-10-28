<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

enum FieldTypeCategory: string
{
    case Text = 'text';

    case Number = 'number';

    case Temporal = 'temporal';

    case Choice = 'choice';

    case Relation = 'relation';

    case Calculated = 'calculated';

    case Special = 'special';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('i18n.backend.enums.custom_fields.field_type_category.text'),
            self::Number => __('i18n.backend.enums.custom_fields.field_type_category.numbers'),
            self::Temporal => __('i18n.backend.enums.custom_fields.field_type_category.date'),
            self::Choice => __('i18n.backend.enums.custom_fields.field_type_category.selection'),
            self::Relation => __('i18n.backend.enums.custom_fields.field_type_category.relationship'),
            self::Calculated => __('i18n.backend.enums.custom_fields.field_type_category.calculated'),
            self::Special => __('i18n.backend.enums.custom_fields.field_type_category.special'),
        };
    }

    public function position(): int
    {
        return match ($this) {
            self::Text => 1,
            self::Number => 2,
            self::Temporal => 3,
            self::Choice => 4,
            self::Relation => 5,
            self::Calculated => 6,
            self::Special => 7,
        };
    }
}
