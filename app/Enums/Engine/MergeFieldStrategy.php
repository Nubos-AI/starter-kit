<?php

declare(strict_types=1);

namespace App\Enums\Engine;

use App\Enums\CustomFields\FieldType;

enum MergeFieldStrategy: string
{
    case PreferNonEmpty = 'prefer_non_empty';

    case PreferTarget = 'prefer_target';

    case PreferSource = 'prefer_source';

    case PreferNewest = 'prefer_newest';

    case PreferOldest = 'prefer_oldest';

    case Concatenate = 'concatenate';

    case Union = 'union';

    case Sum = 'sum';

    case Max = 'max';

    case Min = 'min';

    case Manual = 'manual';

    public function supports(FieldType $fieldType): bool
    {
        return match ($this) {
            self::PreferNonEmpty, self::PreferTarget, self::PreferSource,
            self::PreferNewest, self::PreferOldest, self::Manual => true,
            self::Concatenate => $fieldType === FieldType::TextLong,
            self::Union => in_array($fieldType, [
                FieldType::MultiSelect,
                FieldType::RelationHasMany,
                FieldType::RelationManyToMany,
            ], true),
            self::Sum => $fieldType->isNumericIndex(),
            self::Max, self::Min => $fieldType->isNumericIndex() || in_array($fieldType, [
                FieldType::Date,
                FieldType::DateTime,
            ], true),
        };
    }

    /**
     * @return list<MergeFieldStrategy>
     */
    public static function supportedBy(FieldType $fieldType): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $strategy): bool => $strategy->supports($fieldType),
        ));
    }
}
