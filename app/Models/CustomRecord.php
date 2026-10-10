<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Engine\RecordTreeNode;
use App\Observers\ApprovalCancellationObserver;
use App\Observers\CustomRecordWatcherObserver;
use App\Observers\RecordCascadeObserver;
use App\Observers\RecordLinkCascadeObserver;
use App\Policies\Engine\CustomRecordPolicy;
use App\Scopes\TeamRecordAccessScope;
use App\Scopes\TenantScope;
use App\Traits\Engine\BelongsToObjectType;
use App\Traits\Engine\HasDynamicFields;
use App\Traits\Engine\HasRecordRelations;
use App\Traits\Modules\HasModuleAttributes;
use App\Traits\Search\SearchableRecord;
use App\Traits\Tenancy\BelongsToTenant;
use Database\Factories\CustomRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\Table;
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
 * @property string|null $team_id
 * @property string|null $owner_id
 * @property string $object_type_id
 * @property string|null $pipeline_id
 * @property string|null $stage_id
 * @property string|null $merged_into_record_id
 * @property string|null $record_number
 * @property string|null $external_reference_id
 * @property string|null $deletion_reason
 * @property int $version
 * @property array<string, mixed>|null $data
 * @property Carbon|null $stage_entered_at
 * @property Carbon|null $merged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[ObservedBy([
    RecordCascadeObserver::class,
    ApprovalCancellationObserver::class,
    CustomRecordWatcherObserver::class,
    RecordLinkCascadeObserver::class,
])]
#[ScopedBy([TenantScope::class, TeamRecordAccessScope::class])]
#[UsePolicy(CustomRecordPolicy::class)]
#[Table('custom_records')]
#[Fillable(
    [
        'tenant_id',
        'team_id',
        'owner_id',
        'object_type_id',
        'merged_into_record_id',
        'record_number',
        'external_reference_id',
        'deletion_reason',
        'version',
        'data',
        'merged_at',
    ]
)]
class CustomRecord extends Model
{
    /** @use HasFactory<CustomRecordFactory> */
    use HasFactory;
    use HasModuleAttributes;
    use BelongsToObjectType;
    use BelongsToTenant;
    use HasDynamicFields;
    use HasRecordRelations;
    use HasUlids;
    use SearchableRecord;
    use SoftDeletes;

    /** @var list<RecordTreeNode> */
    public array $ancestorChainBeforeDelete = [];

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
            'data' => 'array',
            'version' => 'integer',
            'merged_at' => 'datetime',
        ];
    }
}
