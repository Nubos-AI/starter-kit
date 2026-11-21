<?php

declare(strict_types=1);

namespace App\Http\Resources\Approvals;

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Approvals\ApprovalAnchorKind;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalDefinition
 */
class ApprovalDefinitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ApprovalDefinition $definition */
        $definition = $this->resource;

        return [
            'id' => (string) $definition->getKey(),
            'stage_transition_id' => $definition->anchor_type === config('modules.approvals.record_anchor')
                ? (string) $definition->anchor_id
                : null,
            'anchor_kind' => ApprovalAnchorKind::forModelClass($definition->anchor_type)?->value,
            'rejection_stage_transition_id' => $definition->rejection_stage_transition_id === null
                ? null
                : $definition->rejection_stage_transition_id,
            'is_active' => $definition->is_active,
            'exclusions' => $definition->exclusions,
            'stages' => $this->stages($definition),
            'updated_at' => $definition->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stages(ApprovalDefinition $definition): array
    {
        return array_values(
            $definition->stages
                ->sortBy('position')
                ->map(static function (ApprovalDefinitionStage $stage): array {
                    /** @var array<string, mixed> $candidateSources */
                    $candidateSources = $stage->candidate_sources;

                    /** @var array<string, mixed>|null $escalationSources */
                    $escalationSources = $stage->escalation_sources;

                    return [
                        'position' => $stage->position,
                        'quorum_type' => $stage->quorum_type->value,
                        'quorum_count' => $stage->quorum_count,
                        'deadline_hours' => $stage->deadline_hours,
                        'escalation_type' => $stage->escalation_type?->value,
                        'candidate_sources' => CandidateCircle::fromArray($candidateSources)->toArray(),
                        'escalation_sources' => $escalationSources === null
                            ? null
                            : CandidateCircle::fromArray($escalationSources)->toArray(),
                    ];
                })
                ->all(),
        );
    }
}
