<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $import_max_file_bytes
 * @property int $import_max_rows
 * @property int $bulk_grouping_threshold
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property array<string, array<string, bool>>|null $preference_policy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(
    [
        'tenant_id',
        'import_max_file_bytes',
        'import_max_rows',
        'bulk_grouping_threshold',
        'quiet_hours_start',
        'quiet_hours_end',
        'preference_policy',
    ]
)]
class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use HasFactory;
    use HasUlids;

    private static int $defaultImportMaxFileBytes = 10_485_760;

    private static int $defaultImportMaxRows = 50_000;

    private static int $defaultBulkGroupingThreshold = 10;

    public static function forTenant(string $tenantId): TenantSetting
    {
        return static::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'import_max_file_bytes' => self::$defaultImportMaxFileBytes,
                'import_max_rows' => self::$defaultImportMaxRows,
                'bulk_grouping_threshold' => self::$defaultBulkGroupingThreshold,
                'quiet_hours_start' => null,
                'quiet_hours_end' => null,
                'preference_policy' => null,
            ]
        );
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'import_max_file_bytes' => 'integer',
            'import_max_rows' => 'integer',
            'bulk_grouping_threshold' => 'integer',
            'preference_policy' => 'array',
        ];
    }
}
