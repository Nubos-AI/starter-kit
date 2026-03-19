<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $slug = Str::slug($name);

        $attributes = [
            'name' => $name,
            'slug' => $slug,
        ];

        if (config('nubos.database_strategy') === 'multi') {
            $attributes['database'] = 'tenant_' . Str::snake(Str::replace('-', '_', $slug));
        }

        return $attributes;
    }
}
