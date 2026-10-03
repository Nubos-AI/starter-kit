<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Segment;
use App\Models\SegmentShare;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SegmentShare>
 */
class SegmentShareFactory extends Factory
{
    protected $model = SegmentShare::class;

    public function definition(): array
    {
        $tenant = Tenant::factory();

        return [
            'tenant_id' => $tenant,
            'segment_id' => Segment::factory(),
            'grantee_type' => (new User)->getMorphClass(),
            'grantee_id' => User::factory(),
            'can_edit' => false,
            'granted_by' => User::factory(),
        ];
    }

    public function canEdit(): static
    {
        return $this->state(fn (array $attributes): array => [
            'can_edit' => true,
        ]);
    }
}
