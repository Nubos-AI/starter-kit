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
 * @property string $object_type_id
 * @property string $record_id
 * @property string|null $triggered_by_automation_id
 * @property string|null $root_run_id
 * @property int $version
 * @property array<int, string> $changed_field_keys
 * @property Carbon $created_at
 * @property Carbon|null $published_at
 */
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'record_id',
        'triggered_by_automation_id',
        'root_run_id',
        'version',
        'changed_field_keys',
        'created_at',
        'published_at',
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
            'version' => 'integer',
            'sequence' => 'integer',
            'created_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }
}
