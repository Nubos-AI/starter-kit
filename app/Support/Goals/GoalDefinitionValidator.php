<?php

declare(strict_types=1);

namespace App\Support\Goals;

use App\Enums\CustomFields\FieldType;
use App\Enums\Goals\GoalScopeType;
use App\Models\FieldDefinition;
use App\Models\Report;
use App\Models\Team;
use App\Models\User;
use App\Support\Engine\SystemFilterFields;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

class GoalDefinitionValidator
{
    public function __construct(
        private readonly GoalScopeCompiler $scopeCompiler,
        private readonly SystemFilterFields $systemFields,
        private readonly GoalDefinitionSource $source,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function validate(User $actor, array $validated): Report
    {
        $report = $this->singleMetricReport($actor, $validated);
        $scopeType = GoalInputRules::scopeType($validated);

        $this->assertGovernedTarget($actor, $scopeType, $validated);
        $this->assertScopeField($report, $scopeType, $validated);
        $this->assertPeriodField($report, $validated);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertPeriodField(Report $report, array $validated): void
    {
        $key = GoalInputRules::periodFieldKey($validated);

        if ($key === null) {
            return;
        }

        $field = $this->source->field($report->object_type_id, $key);

        $usable = $field instanceof FieldDefinition
            && $field->field_type === FieldType::Date
            && $field->is_filterable
            && !$field->is_encrypted;

        if (!$usable) {
            throw ValidationException::withMessages([
                'period_field_key' => __('i18n.backend.support.goals.goal_definition_validator.the_period_field_must_be_a_filterable_unencrypted_date'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function singleMetricReport(User $actor, array $validated): Report
    {
        $report = $this->source->report(
            TenantContext::currentId((string) $actor->tenant_id),
            (string) $validated['report_id'],
        );

        if (!$report instanceof Report || $actor->cannot('view', $report)) {
            throw ValidationException::withMessages([
                'report_id' => __('i18n.backend.support.goals.goal_definition_validator.the_selected_report_does_not_exist'),
            ]);
        }

        if ($report->group_by_field_key !== null || $report->series_field_key !== null) {
            throw ValidationException::withMessages([
                'report_id' => __('i18n.backend.support.goals.goal_definition_validator.a_goal_requires_a_report_with_exactly_one_metric'),
            ]);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertGovernedTarget(User $actor, GoalScopeType $scopeType, array $validated): void
    {
        match ($scopeType) {
            GoalScopeType::User => $this->assertGovernedUser($actor, $validated),
            GoalScopeType::Team => $this->assertGovernedTeam($actor, $validated),
            GoalScopeType::Tenant => $this->assertGovernedTenant($actor),
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertGovernedUser(User $actor, array $validated): void
    {
        $targetUserId = $validated['target_user_id'] ?? null;

        $exists = is_string($targetUserId) && $this->source->hasUser(
            TenantContext::currentId((string) $actor->tenant_id),
            $targetUserId,
        );

        if (!$exists) {
            throw ValidationException::withMessages([
                'target_user_id' => __('i18n.backend.support.goals.goal_definition_validator.a_personal_goal_requires_a_person_from_this_tenant'),
            ]);
        }

        if ($targetUserId !== $actor->getKey() && !$actor->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'target_user_id' => __('i18n.backend.support.goals.goal_definition_validator.setting_a_goal_for_another_person_requires_elevated_permissions'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertGovernedTeam(User $actor, array $validated): void
    {
        $targetTeamId = $validated['target_team_id'] ?? null;

        $exists = is_string($targetTeamId) && $this->source->hasTeam(
            TenantContext::currentId((string) $actor->tenant_id),
            $targetTeamId,
        );

        if (!$exists) {
            throw ValidationException::withMessages([
                'target_team_id' => __('i18n.backend.support.goals.goal_definition_validator.a_team_goal_requires_a_team_from_this_tenant'),
            ]);
        }

        $isMember = $actor->teams->contains(
            fn (Team $team): bool => $team->getKey() === $targetTeamId,
        );

        if (!$isMember && !$actor->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'target_team_id' => __('i18n.backend.support.goals.goal_definition_validator.setting_a_goal_for_a_team_you_do_not'),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertGovernedTenant(User $actor): void
    {
        if (!$actor->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'scope_type' => __('i18n.backend.support.goals.goal_definition_validator.setting_a_goal_for_the_entire_tenant_requires_elevated'),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertScopeField(Report $report, GoalScopeType $scopeType, array $validated): void
    {
        $chosen = $validated['scope_field_key'] ?? null;
        $hasChosen = is_string($chosen) && $chosen !== '';

        if ($scopeType === GoalScopeType::Tenant) {
            if ($hasChosen) {
                throw ValidationException::withMessages([
                    'scope_field_key' => __('i18n.backend.support.goals.goal_definition_validator.a_tenant_goal_applies_to_the_entire_tenant_and'),
                ]);
            }

            return;
        }

        $key = (string) GoalInputRules::scopeFieldKey($validated, $scopeType);
        $field = $this->scopeField($report, $key);

        if (!$field instanceof FieldDefinition || !$this->scopeCompiler->supportsScopeField($field)) {
            throw ValidationException::withMessages([
                'scope_field_key' => __('i18n.backend.support.goals.goal_definition_validator.the_restricting_field_must_belong_to_the_analysed_object'),
            ]);
        }
    }

    private function scopeField(Report $report, string $key): ?FieldDefinition
    {
        $objectTypeId = $report->object_type_id;

        $field = $this->source->field($objectTypeId, $key);

        if ($field instanceof FieldDefinition) {
            return $field;
        }

        $systemField = $this->systemFields->all($objectTypeId)->firstWhere('key', $key);

        return $systemField instanceof FieldDefinition ? $systemField : null;
    }
}
