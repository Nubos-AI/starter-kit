<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OutboxEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $sequence
 * @property string $tenant_id
 * @property string $topic
 * @property string|null $object_type_id
 * @property string|null $record_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $triggered_by_automation_id
 * @property string|null $root_run_id
 * @property int|null $version
 * @property array<int, string>|null $changed_field_keys
 * @property array<string, mixed>|null $payload
 * @property int $attempts
 * @property Carbon $created_at
 * @property Carbon|null $available_at
 * @property Carbon|null $published_at
 * @property Carbon|null $failed_at
 */
#[Fillable(
    [
        'tenant_id',
        'topic',
        'object_type_id',
        'record_id',
        'subject_type',
        'subject_id',
        'triggered_by_automation_id',
        'root_run_id',
        'version',
        'changed_field_keys',
        'payload',
        'attempts',
        'created_at',
        'available_at',
        'published_at',
        'failed_at',
    ]
)]
class OutboxEvent extends Model
{
    /** @use HasFactory<OutboxEventFactory> */
    use HasFactory;
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changed_field_keys' => 'array',
            'payload' => 'array',
            'version' => 'integer',
            'attempts' => 'integer',
            'sequence' => 'integer',
            'created_at' => 'datetime',
            'available_at' => 'datetime',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
