<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FieldGroup;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldGroup>
 */
class FieldGroupFactory extends Factory
{
    public function definition(): array
    {
        $key = fake()->unique()->word();

        return [
            'object_type_id' => ObjectType::factory(),
            'key' => $key,
            'i18n_labels' => ['de' => ucfirst($key), 'en' => ucfirst($key)],
            'position' => 0,
        ];
    }
}
