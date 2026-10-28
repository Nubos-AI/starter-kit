<?php

declare(strict_types=1);

namespace App\Support\Segments;

class SystemSegmentRegistry
{
    /**
     * @return list<SystemSegmentDescriptor>
     */
    public function all(): array
    {
        return [
            new SystemSegmentDescriptor(
                id: 'system:mine',
                name: __('i18n.backend.support.segments.system_segment_registry.mine'),
                objectTypeId: null,
                ownerScoped: true,
                sort: ['column' => 'updated_at', 'direction' => 'desc'],
            ),
            new SystemSegmentDescriptor(
                id: 'system:recent',
                name: __('i18n.backend.support.segments.system_segment_registry.recently_changed'),
                objectTypeId: null,
                ownerScoped: false,
                sort: ['column' => 'updated_at', 'direction' => 'desc'],
            ),
        ];
    }

    public function find(string $id): ?SystemSegmentDescriptor
    {
        foreach ($this->all() as $descriptor) {
            if ($descriptor->id === $id) {
                return $descriptor;
            }
        }

        return null;
    }
}
