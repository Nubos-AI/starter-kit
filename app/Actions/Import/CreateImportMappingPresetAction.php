<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Models\ImportMappingPreset;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Import\ImportMappingValidator;
use Illuminate\Support\Facades\Validator;

class CreateImportMappingPresetAction
{
    public function __construct(private readonly ImportMappingValidator $mappingValidator) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ObjectType $objectType, User $user, array $input): ImportMappingPreset
    {
        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'mapping' => ['required', 'array'],
            ]
        )->validate();

        /** @var array<string, mixed> $mapping */
        $mapping = $validated['mapping'];

        $this->mappingValidator->validate($objectType, $user, $mapping);

        return ImportMappingPreset::query()->create([
            'object_type_id' => $objectType->getKey(),
            'user_id' => (string) $user->getKey(),
            'name' => (string) $validated['name'],
            'mapping' => $mapping,
        ]);
    }
}
