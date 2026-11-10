<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImportMappingPreset;
use App\Models\ObjectType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportMappingPreset>
 */
class ImportMappingPresetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'object_type_id' => ObjectType::factory(),
            'name' => Str::title(fake()->word().' '.fake()->word()),
            'mapping' => [
                'columns' => ['Name' => 'name'],
                'formats' => ['Name' => 'text'],
                'duplicate_mode' => 'skip',
            ],
        ];
    }
}
