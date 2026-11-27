<?php

declare(strict_types=1);

namespace App\Enums\Import;

use App\Enums\CustomFields\FieldType;

enum ImportColumnFormat: string
{
    case Text = 'text';

    case Integer = 'integer';

    case Decimal = 'decimal';

    case Money = 'money';

    case Date = 'date';

    case DateTime = 'datetime';

    case Boolean = 'boolean';

    case SingleSelect = 'single_select';

    case MultiSelect = 'multi_select';

    case Relation = 'relation';

    public static function forFieldType(FieldType $fieldType): self
    {
        return match ($fieldType) {
            FieldType::Number => self::Integer,
            FieldType::Decimal => self::Decimal,
            FieldType::Money => self::Money,
            FieldType::Date => self::Date,
            FieldType::DateTime => self::DateTime,
            FieldType::Boolean => self::Boolean,
            FieldType::SingleSelect => self::SingleSelect,
            FieldType::MultiSelect => self::MultiSelect,
            FieldType::RelationHasMany, FieldType::RelationManyToMany => self::Relation,
            default => self::Text,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Text => __('i18n.backend.enums.import.import_column_format.text'),
            self::Integer => __('i18n.backend.enums.import.import_column_format.integer'),
            self::Decimal => __('i18n.backend.enums.import.import_column_format.decimal'),
            self::Money => __('i18n.backend.enums.import.import_column_format.amount'),
            self::Date => __('i18n.backend.enums.import.import_column_format.date'),
            self::DateTime => __('i18n.backend.enums.import.import_column_format.date_and_time'),
            self::Boolean => 'Ja/Nein',
            self::SingleSelect => __('i18n.backend.enums.import.import_column_format.single_selection'),
            self::MultiSelect => __('i18n.backend.enums.import.import_column_format.multiple_selection'),
            self::Relation => __('i18n.backend.enums.import.import_column_format.relationship'),
        };
    }
}
