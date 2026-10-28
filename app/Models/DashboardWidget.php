<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Reports\ChartType;
use App\Policies\Dashboards\DashboardWidgetPolicy;
use App\Scopes\TenantScope;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $dashboard_id
 * @property string|null $report_id
 * @property string|null $goal_id
 * @property string|null $title
 * @property ChartType|null $chart_type
 * @property array<string, mixed>|null $definition
 * @property int $position
 * @property int $column_span
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ScopedBy([TenantScope::class])]
#[UsePolicy(DashboardWidgetPolicy::class)]
#[Fillable(
    [
        'tenant_id',
        'dashboard_id',
        'report_id',
        'goal_id',
        'title',
        'chart_type',
        'definition',
        'position',
        'column_span',
    ]
)]
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory;
    use BelongsToTenant;
    use HasUlids;

    /**
     * @return BelongsTo<Dashboard, $this>
     */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chart_type' => ChartType::class,
            'definition' => 'array',
        ];
    }
}
