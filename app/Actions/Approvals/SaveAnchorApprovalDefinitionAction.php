<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\DTOs\Approvals\ApprovalExclusionSet;
use App\Enums\Approvals\ApprovalAnchorKind;
use App\Models\ApprovalDefinition;
use App\Support\Approvals\ApprovalDefinitionValidator;
use App\Traits\Approvals\PersistsApprovalStages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class SaveAnchorApprovalDefinitionAction
{
    use PersistsApprovalStages;

    public function __construct(private readonly ApprovalDefinitionValidator $definitionValidator) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(ApprovalAnchorKind $kind, array $input): ApprovalDefinition
    {
        $validated = Validator::make($input, [
            'is_active' => ['required', 'boolean'],
            'rejection_stage_transition_id' => ['prohibited'],
            'exclusions' => ['prohibited'],
            ...$this->stageValidationRules(),
        ])->validate();

        $stages = $this->normalizeStages($validated['stages']);

        if ($validated['is_active']) {
            $this->definitionValidator->assertAnchorConfiguration(['stages' => $stages]);
        }

        return DB::transaction(function () use ($kind, $validated, $stages): ApprovalDefinition {
            $definition = $this->storedDefinition($kind);

            $state = [
                'is_active' => (bool) $validated['is_active'],
                'exclusions' => ApprovalExclusionSet::strict()->toArray(),
            ];

            if ($definition instanceof ApprovalDefinition) {
                $definition->update($state);
            } else {
                $definition = ApprovalDefinition::query()->create([
                    'anchor_type' => $kind->modelClass(),
                    'anchor_id' => null,
                    'rejection_stage_transition_id' => null,
                    ...$state,
                ]);
            }

            $this->replaceStages($definition, $stages);

            return $definition->refresh();
        });
    }

    private function storedDefinition(ApprovalAnchorKind $kind): ?ApprovalDefinition
    {
        return ApprovalDefinition::query()
            ->where('anchor_type', $kind->modelClass())
            ->whereNull('anchor_id')
            ->first();
    }
}
