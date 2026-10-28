<?php

declare(strict_types=1);

namespace App\Enums\Engine;

enum RelationCardinality: string
{
    case OneToMany = 'one_to_many';

    case ManyToMany = 'many_to_many';

    public function label(): string
    {
        return match ($this) {
            self::OneToMany => __('i18n.backend.enums.engine.relation_cardinality.one_to_many'),
            self::ManyToMany => __('i18n.backend.enums.engine.relation_cardinality.many_to_many'),
        };
    }
}
