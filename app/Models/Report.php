<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Reports\AggregationType;
use App\Enums\Reports\ChartType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportExecutionMode;
use App\Policies\Reports\ReportPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $owner_id
 * @property string $object_type_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed>|null $filter_definition
 * @property AggregationType $aggregation_type
 * @property string|null $aggregation_field_key
 * @property string|null $group_by_field_key
 * @property GroupingBucket|null $group_by_bucket
 * @property string|null $series_field_key
 * @property ChartType $chart_type
 * @property ReportExecutionMode $execution_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(ReportPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'owner_id',
        'object_type_id',
        'name',
        'description',
        'filter_definition',
        'aggregation_type',
        'aggregation_field_key',
        'group_by_field_key',
        'group_by_bucket',
        'series_field_key',
        'chart_type',
        'execution_mode',
    ]
)]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<ObjectType, $this>
     */
    public function objectType(): BelongsTo
    {
        return $this->belongsTo(ObjectType::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filter_definition' => 'array',
            'aggregation_type' => AggregationType::class,
            'group_by_bucket' => GroupingBucket::class,
            'chart_type' => ChartType::class,
            'execution_mode' => ReportExecutionMode::class,
        ];
    }
}
