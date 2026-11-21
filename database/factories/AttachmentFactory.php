<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\CustomRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
class AttachmentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->slug(2).'.pdf';

        return [
            'tenant_id' => Tenant::factory(),
            'record_id' => CustomRecord::factory(),
            'field_key' => 'attachment',
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1024, 1048576),
            'disk' => config('filesystems.default'),
            'path' => 'attachments/'.Str::ulid().'.pdf',
            'uploaded_by' => User::factory(),
        ];
    }
}
