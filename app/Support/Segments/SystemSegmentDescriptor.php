<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Models\Segment;
use App\Models\User;

readonly class SystemSegmentDescriptor
{
    /**
     * @param  array{column: string, direction: 'asc'|'desc'}|null  $sort
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $objectTypeId,
        public bool $ownerScoped,
        public ?array $sort = null,
    ) {}

    public function toSegment(User $viewer): Segment
    {
        $segment = new Segment;

        $segment->forceFill([
            'id' => $this->id,
            'tenant_id' => $viewer->tenant_id,
            'owner_id' => null,
            'name' => $this->name,
            'object_type_id' => $this->objectTypeId,
            'filter_definition' => [],
            'is_system' => true,
            'is_default' => false,
        ]);

        $segment->exists = false;

        return $segment;
    }
}
