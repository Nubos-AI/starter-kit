<?php

declare(strict_types=1);

namespace App\Enums\CustomFields;

enum FieldType: string
{
    case TextShort = 'text_short';

    case TextLong = 'text_long';

    case Number = 'number';

    case Decimal = 'decimal';

    case Money = 'money';

    case Date = 'date';

    case DateTime = 'datetime';

    case Boolean = 'boolean';

    case SingleSelect = 'single_select';

    case MultiSelect = 'multi_select';

    case RelationHasMany = 'relation_has_many';

    case RelationManyToMany = 'relation_many_to_many';

    case Email = 'email';

    case Phone = 'phone';

    case Url = 'url';

    case File = 'file';

    case GeoAddress = 'geo_address';

    case Computed = 'computed';

    case Rollup = 'rollup';

    public function label(): string
    {
        return match ($this) {
            self::TextShort => __('i18n.backend.enums.custom_fields.field_type.short_text'),
            self::TextLong => __('i18n.backend.enums.custom_fields.field_type.long_text'),
            self::Number => __('i18n.backend.enums.custom_fields.field_type.integer'),
            self::Decimal => __('i18n.backend.enums.custom_fields.field_type.decimal'),
            self::Money => __('i18n.backend.enums.custom_fields.field_type.amount'),
            self::Date => __('i18n.backend.enums.custom_fields.field_type.date'),
            self::DateTime => __('i18n.backend.enums.custom_fields.field_type.date_and_time'),
            self::Boolean => 'Ja/Nein',
            self::SingleSelect => __('i18n.backend.enums.custom_fields.field_type.single_selection'),
            self::MultiSelect => __('i18n.backend.enums.custom_fields.field_type.multiple_selection'),
            self::RelationHasMany => __('i18n.backend.enums.custom_fields.field_type.relationship_one_to_many'),
            self::RelationManyToMany => __('i18n.backend.enums.custom_fields.field_type.relationship_many_to_many'),
            self::Email => __('i18n.backend.enums.custom_fields.field_type.email_address'),
            self::Phone => __('i18n.backend.enums.custom_fields.field_type.phone_number'),
            self::Url => __('i18n.backend.enums.custom_fields.field_type.web_address'),
            self::File => __('i18n.backend.enums.custom_fields.field_type.file'),
            self::GeoAddress => __('i18n.backend.enums.custom_fields.field_type.postal_address'),
            self::Computed => __('i18n.backend.enums.custom_fields.field_type.formula'),
            self::Rollup => __('i18n.backend.enums.custom_fields.field_type.aggregation'),
        };
    }

    public function category(): FieldTypeCategory
    {
        return match ($this) {
            self::TextShort, self::TextLong, self::Email, self::Phone, self::Url => FieldTypeCategory::Text,
            self::Number, self::Decimal, self::Money => FieldTypeCategory::Number,
            self::Date, self::DateTime => FieldTypeCategory::Temporal,
            self::Boolean, self::SingleSelect, self::MultiSelect => FieldTypeCategory::Choice,
            self::RelationHasMany, self::RelationManyToMany => FieldTypeCategory::Relation,
            self::Computed, self::Rollup => FieldTypeCategory::Calculated,
            self::File, self::GeoAddress => FieldTypeCategory::Special,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::TextShort => __('i18n.backend.enums.custom_fields.field_type.a_single_line_of_text_such_as_a_name'),
            self::TextLong => __('i18n.backend.enums.custom_fields.field_type.multiple_lines_of_text_such_as_a_note'),
            self::Number => __('i18n.backend.enums.custom_fields.field_type.a_whole_number_without_decimal_places'),
            self::Decimal => __('i18n.backend.enums.custom_fields.field_type.a_number_with_decimal_places'),
            self::Money => __('i18n.backend.enums.custom_fields.field_type.an_amount_with_a_currency'),
            self::Date => __('i18n.backend.enums.custom_fields.field_type.a_day_without_a_time'),
            self::DateTime => __('i18n.backend.enums.custom_fields.field_type.a_date_with_a_time'),
            self::Boolean => __('i18n.backend.enums.custom_fields.field_type.yes_or_no'),
            self::SingleSelect => __('i18n.backend.enums.custom_fields.field_type.one_value_from_a_fixed_list'),
            self::MultiSelect => __('i18n.backend.enums.custom_fields.field_type.multiple_values_from_a_fixed_list'),
            self::RelationHasMany => __('i18n.backend.enums.custom_fields.field_type.references_multiple_records_of_another_object_type'),
            self::RelationManyToMany => __('i18n.backend.enums.custom_fields.field_type.freely_links_records_on_both_sides'),
            self::Email => __('i18n.backend.enums.custom_fields.field_type.email_address_with_format_validation'),
            self::Phone => __('i18n.backend.enums.custom_fields.field_type.phone_number_2'),
            self::Url => __('i18n.backend.enums.custom_fields.field_type.web_address_with_format_validation'),
            self::File => __('i18n.backend.enums.custom_fields.field_type.an_uploaded_file'),
            self::GeoAddress => __('i18n.backend.enums.custom_fields.field_type.an_address_with_street_city_and_country'),
            self::Computed => __('i18n.backend.enums.custom_fields.field_type.calculates_a_value_from_other_fields_of_the_same'),
            self::Rollup => __('i18n.backend.enums.custom_fields.field_type.aggregates_values_from_related_records_for_example_as_a'),
        };
    }

    public function isTemporal(): bool
    {
        return match ($this) {
            self::Date, self::DateTime => true,
            default => false,
        };
    }

    public function isNumericIndex(): bool
    {
        return match ($this) {
            self::Number, self::Decimal, self::Money => true,
            default => false,
        };
    }

    public function isFreeTextSearchable(): bool
    {
        return match ($this) {
            self::TextShort, self::TextLong, self::Number, self::Decimal, self::Money,
            self::SingleSelect, self::Email, self::Phone, self::Url => true,
            default => false,
        };
    }

    public function isTypeChangeable(): bool
    {
        return match ($this) {
            self::RelationHasMany, self::RelationManyToMany, self::File,
            self::GeoAddress, self::Rollup, self::Computed => false,
            default => true,
        };
    }
}
