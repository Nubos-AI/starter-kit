<?php

declare(strict_types=1);

namespace App\Actions\Export;

use App\Models\ExportFieldPreset;
use Illuminate\Support\Facades\Validator;

class UpdateExportFieldPresetAction
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(ExportFieldPreset $preset, array $input): ExportFieldPreset
    {
        $validated = Validator::make(
            $input,
            [
                'name' => ['required', 'string', 'max:255'],
                'fields' => ['required', 'array'],
                'fields.*' => ['string'],
            ]
        )->validate();

        $preset->fill([
            'name' => (string) $validated['name'],
            'fields' => array_values(array_filter($validated['fields'], is_string(...))),
        ]);
        $preset->save();

        return $preset;
    }
}
