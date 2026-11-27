<?php

declare(strict_types=1);

namespace App\Enums\Timeline;

enum RelationTimelineAction: string
{
    case Linked = 'linked';

    case Unlinked = 'unlinked';

    public function label(): string
    {
        return match ($this) {
            self::Linked => __('i18n.backend.enums.timeline.relation_timeline_action.linked'),
            self::Unlinked => __('i18n.backend.enums.timeline.relation_timeline_action.relationship_removed'),
        };
    }
}
