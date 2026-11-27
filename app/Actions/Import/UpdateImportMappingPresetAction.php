<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Models\ImportMappingPreset;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Import\ImportMappingValidator;
use Illuminate\Support\Facades\Validator;

class UpdateImportMappingPresetAction
{
    public function __construct(private readonly ImportMappingValidator $mappingValidator) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ObjectType $objectType, User $user, ImportMappingPreset $preset, array $input): ImportMappingPreset
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

        $preset->fill([
            'name' => (string) $validated['name'],
            'mapping' => $mapping,
        ]);
        $preset->save();

        return $preset;
    }
}
