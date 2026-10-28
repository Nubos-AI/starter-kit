<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\Segment;
use App\Models\User;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Segments\SegmentFilterGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateSegmentAction
{
    public function __construct(
        private readonly SegmentFilterGuard $filterGuard,
        private readonly ObjectTypeRegistry $objectTypes,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     */
    public function execute(User $owner, array $input): Segment
    {
        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'object_type_id' => [
                    'required',
                    'string',
                    Rule::exists('object_types', 'id')->where('tenant_id', (string) TenantContext::currentId()),
                ],
                'filter_definition' => ['nullable', 'array'],
            ]
        )->validate();

        $objectType = $this->objectTypes->byId((string) $validated['object_type_id']);

        if (!$owner->hasPermission("{$objectType->slug}.view")) {
            throw new AuthorizationException(__('i18n.backend.actions.segments.create_segment_action.you_may_not_view_records_of_this_object_type'));
        }

        $filterDefinition = $validated['filter_definition'] ?? [];

        $this->filterGuard->assertValid($objectType, $filterDefinition);

        return Segment::query()->create([
            'name' => $validated['name'],
            'object_type_id' => $objectType->getKey(),
            'filter_definition' => $filterDefinition,
            'is_default' => false,
            'is_system' => false,
            'owner_id' => $owner->getKey(),
        ]);
    }
}
