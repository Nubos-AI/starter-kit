<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\Segment;
use App\Models\User;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Segments\SegmentFilterGuard;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class UpdateSegmentAction
{
    public function __construct(
        private readonly SegmentFilterGuard $filterGuard,
        private readonly ObjectTypeRegistry $objectTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actor, Segment $segment, array $input): Segment
    {
        Gate::forUser($actor)->authorize('update', $segment);

        $validated = Validator::make(
            $input,
            [
                'name' => ['sometimes', 'string', 'max:255'],
                'filter_definition' => ['sometimes', 'nullable', 'array'],
            ]
        )->validate();

        $attributes = array_intersect_key($validated, array_flip(['name']));

        if ($segment->object_type_id !== null && array_key_exists('filter_definition', $validated)) {
            $objectType = $this->objectTypes->byId((string) $segment->object_type_id);
            $tree = $validated['filter_definition'] ?? [];

            $this->filterGuard->assertValid($objectType, $tree);

            $attributes['filter_definition'] = $tree;
        }

        $segment->fill($attributes);
        $segment->save();

        return $segment;
    }
}
