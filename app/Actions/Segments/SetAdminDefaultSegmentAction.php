<?php

declare(strict_types=1);

namespace App\Actions\Segments;

use App\Models\Segment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SetAdminDefaultSegmentAction
{
    public function __construct(private readonly SetSegmentDefaultAction $setDefault) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actor, array $input): Segment
    {
        $validated = Validator::make($input, [
            'object_type_id' => [
                'required',
                'string',
                Rule::exists('object_types', 'id')->where('tenant_id', (string) TenantContext::currentId()),
            ],
            'segment_id' => [
                'required',
                'string',
                Rule::exists('segments', 'id')->where('tenant_id', (string) TenantContext::currentId()),
            ],
        ])->validate();

        $segment = Segment::query()
            ->whereKey($validated['segment_id'])
            ->where('object_type_id', $validated['object_type_id'])
            ->firstOrFail();

        return $this->setDefault->execute($actor, $segment, true);
    }
}
