<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ConfigBundle\ArtifactKind;
use App\Models\ConfigurationArtifactVersion;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConfigurationArtifactVersion>
 */
class ConfigurationArtifactVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'snapshot_group_id' => (string) Str::ulid(),
            'artifact_kind' => ArtifactKind::Skills,
            'artifact_key' => fake()->unique()->word(),
            'artifact_id' => (string) Str::ulid(),
            'version_number' => 1,
            'snapshot' => ['key' => fake()->word()],
            'actor_id' => null,
            'change_summary' => fake()->sentence(),
            'changed_at' => now(),
        ];
    }
}
