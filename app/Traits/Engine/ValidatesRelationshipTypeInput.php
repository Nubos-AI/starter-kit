<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\RelationCardinality;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ValidatesRelationshipTypeInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validatedRelationshipTypeInput(array $input): array
    {
        $tenantId = (string) TenantContext::currentId();

        return Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'inverse_name' => ['required', 'string', 'max:255'],
                'from_object_type_id' => ['required', 'string', Rule::exists('object_types', 'id')->where('tenant_id', $tenantId)],
                'to_object_type_id' => ['required', 'string', Rule::exists('object_types', 'id')->where('tenant_id', $tenantId)],
                'cardinality' => ['required', Rule::enum(RelationCardinality::class)],
                'cascade_behavior' => ['required', Rule::enum(CascadeBehavior::class)],
                'is_required' => ['boolean'],
            ]
        )->validate();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function relationshipTypeAttributes(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'inverse_name' => $validated['inverse_name'],
            'from_object_type_id' => $validated['from_object_type_id'],
            'to_object_type_id' => $validated['to_object_type_id'],
            'cardinality' => $validated['cardinality'],
            'cascade_behavior' => $validated['cascade_behavior'],
            'is_required' => (bool) ($validated['is_required'] ?? false),
        ];
    }
}
