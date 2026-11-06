<?php

declare(strict_types=1);

namespace App\Traits\Approvals;

use App\DTOs\Governance\CandidateCircle;
use App\Enums\Approvals\ApprovalEscalationType;
use App\Enums\Approvals\ApprovalQuorumType;
use App\Models\ApprovalDefinition;
use App\Models\ApprovalDefinitionStage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

trait PersistsApprovalStages
{
    /**
     * @return array<string, list<string|Enum>>
     */
    protected function stageValidationRules(): array
    {
        return [
            'stages' => ['present', 'array'],
            'stages.*' => ['array'],
            'stages.*.quorum_type' => ['required', Rule::enum(ApprovalQuorumType::class)],
            'stages.*.quorum_count' => ['nullable', 'integer'],
            'stages.*.deadline_hours' => ['nullable', 'integer'],
            'stages.*.escalation_type' => ['nullable', Rule::enum(ApprovalEscalationType::class)],
            'stages.*.escalation_sources' => ['nullable', 'array'],
            'stages.*.candidate_sources' => ['required', 'array'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $stages
     * @return list<array{quorum_type: string, quorum_count: int|null, deadline_hours: int|null, escalation_type: string|null, candidate_sources: array<string, mixed>, escalation_sources: array<string, mixed>|null}>
     */
    protected function normalizeStages(array $stages): array
    {
        $normalized = [];

        foreach (array_values($stages) as $stage) {
            $stage = is_array($stage) ? $stage : [];

            $normalized[] = [
                'quorum_type' => (string) ($stage['quorum_type'] ?? ''),
                'quorum_count' => $this->wholeNumber($stage['quorum_count'] ?? null),
                'deadline_hours' => $this->wholeNumber($stage['deadline_hours'] ?? null),
                'escalation_type' => $this->escalationType($stage['escalation_type'] ?? null),
                'candidate_sources' => CandidateCircle::fromArray(
                    is_array($stage['candidate_sources'] ?? null) ? $stage['candidate_sources'] : [],
                )->toArray(),
                'escalation_sources' => $this->circleOrNull($stage['escalation_sources'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $stages
     */
    protected function replaceStages(ApprovalDefinition $definition, array $stages): void
    {
        ApprovalDefinitionStage::query()
            ->where('approval_definition_id', $definition->getKey())
            ->delete();

        foreach ($stages as $index => $stage) {
            ApprovalDefinitionStage::query()->create([
                'approval_definition_id' => $definition->getKey(),
                'position' => $index + 1,
                ...$stage,
            ]);
        }
    }

    private function wholeNumber(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : null;
    }

    private function escalationType(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function circleOrNull(mixed $value): ?array
    {
        if (!is_array($value) || $value === []) {
            return null;
        }

        return CandidateCircle::fromArray($value)->toArray();
    }
}
