<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Export\ExportFormat;
use App\Enums\Export\ExportJobStatus;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ExportJobFactory;
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
 * @property ExportJobStatus $status
 * @property ExportFormat $format
 * @property array<string, mixed> $scope
 * @property array<int, string> $fields
 * @property string|null $result_path
 * @property int $row_count
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
        'format',
        'scope',
        'fields',
        'result_path',
        'row_count',
        'started_at',
        'finished_at',
    ]
)]
class ExportJob extends Model
{
    /** @use HasFactory<ExportJobFactory> */
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
            'status' => ExportJobStatus::class,
            'format' => ExportFormat::class,
            'scope' => 'array',
            'fields' => 'array',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
