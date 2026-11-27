<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalQuorumType;
use Database\Factories\ApprovalDefinitionStageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $approval_definition_id
 * @property int $position
 * @property ApprovalQuorumType $quorum_type
 * @property int|null $quorum_count
 * @property int|null $deadline_hours
 * @property ApprovalEscalationType|null $escalation_type
 * @property list<array<string, mixed>> $candidate_sources
 * @property list<array<string, mixed>>|null $escalation_sources
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(
    [
        'approval_definition_id',
        'position',
        'quorum_type',
        'quorum_count',
        'deadline_hours',
        'escalation_type',
        'candidate_sources',
        'escalation_sources',
    ]
)]
class ApprovalDefinitionStage extends Model
{
    /** @use HasFactory<ApprovalDefinitionStageFactory> */
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    /**
     * @return BelongsTo<ApprovalDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ApprovalDefinition::class, 'approval_definition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quorum_type' => ApprovalQuorumType::class,
            'quorum_count' => 'integer',
            'deadline_hours' => 'integer',
            'escalation_type' => ApprovalEscalationType::class,
            'candidate_sources' => 'array',
            'escalation_sources' => 'array',
        ];
    }
}
