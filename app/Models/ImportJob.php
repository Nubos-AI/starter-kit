<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Import\ImportJobStatus;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ImportJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $object_type_id
 * @property string $user_id
 * @property ImportJobStatus $status
 * @property string $original_filename
 * @property string $source_path
 * @property string $source_disk
 * @property string $format
 * @property array<string, mixed> $mapping
 * @property string $duplicate_mode
 * @property string $missing_option_mode
 * @property int $total_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $error_count
 * @property string|null $error_report_path
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[Fillable(
    [
        'tenant_id',
        'object_type_id',
        'user_id',
        'status',
        'original_filename',
        'source_path',
        'source_disk',
        'format',
        'mapping',
        'duplicate_mode',
        'missing_option_mode',
        'total_rows',
        'created_count',
        'updated_count',
        'error_count',
        'error_report_path',
        'started_at',
        'finished_at',
    ]
)]
class ImportJob extends Model
{
    /** @use HasFactory<ImportJobFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportJobStatus::class,
            'mapping' => 'array',
            'total_rows' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'error_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
