<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Models\ExportFieldPreset;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Validator;

class CreateExportFieldPresetAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ObjectType $objectType, User $owner, array $input): ExportFieldPreset
    {
        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'fields' => ['required', 'array'],
                'fields.*' => ['string'],
            ]
        )->validate();

        return ExportFieldPreset::query()->create([
            'tenant_id' => TenantContext::currentId((string) $owner->tenant_id),
            'object_type_id' => $objectType->getKey(),
            'user_id' => (string) $owner->getKey(),
            'name' => (string) $validated['name'],
            'fields' => array_values(array_filter($validated['fields'], is_string(...))),
        ]);
    }
}
