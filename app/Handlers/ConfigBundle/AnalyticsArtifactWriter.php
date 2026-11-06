<?php

declare(strict_types=1);

namespace App\Handlers\ConfigBundle;

use App\Actions\Aging\CreateAgingRuleAction;
use App\Actions\Aging\DeleteAgingRuleAction;
use App\Actions\Aging\UpdateAgingRuleAction;
use App\Actions\Dashboards\AddDashboardWidgetAction;
use App\Actions\Dashboards\CreateDashboardAction;
use App\Actions\Dashboards\DeleteDashboardAction;
use App\Actions\Dashboards\RemoveDashboardWidgetAction;
use App\Actions\Dashboards\UpdateDashboardAction;
use App\Actions\Dashboards\UpdateDashboardWidgetAction;
use App\Actions\Export\CreateExportFieldPresetAction;
use App\Actions\Export\DeleteExportFieldPresetAction;
use App\Actions\Export\UpdateExportFieldPresetAction;
use App\Actions\Goals\CreateGoalAction;
use App\Actions\Goals\DeleteGoalAction;
use App\Actions\Goals\UpdateGoalAction;
use App\Actions\Import\CreateImportMappingPresetAction;
use App\Actions\Import\DeleteImportMappingPresetAction;
use App\Actions\Import\UpdateImportMappingPresetAction;
use App\Actions\Reports\CreateReportAction;
use App\Actions\Reports\DeleteReportAction;
use App\Actions\Reports\UpdateReportAction;
use App\Actions\Segments\CreateSegmentAction;
use App\Actions\Segments\DeleteSegmentAction;
use App\Actions\Segments\UpdateSegmentAction;
use App\Actions\Skills\CreateSkillAction;
use App\Actions\Skills\DeleteSkillAction;
use App\Actions\Skills\UpdateSkillAction;
use App\Contracts\ConfigBundle\ArtifactWriterInterface;
use App\DTOs\ConfigBundle\ArtifactWriteResult;
use App\DTOs\ConfigBundle\BundleArtifact;
use App\Enums\ConfigBundle\ArtifactKind;
use App\Enums\ConfigBundle\ArtifactWriteAction;
use App\Enums\Goals\GoalScopeType;
use App\Enums\Promotion\DiffState;
use App\Enums\Reports\ReportExecutionMode;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\AgingRule;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\ExportFieldPreset;
use App\Models\Goal;
use App\Models\ImportMappingPreset;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\Segment;
use App\Models\Skill;
use App\Models\User;
use App\Support\ConfigBundle\BundleWriteTenant;
use App\Support\ConfigBundle\TargetKeyResolver;
use App\Support\Reports\ReportInputRules;
use App\Support\Tenancy\ActingUserContext;
use App\Traits\ConfigBundle\ReportsArtifactWriteResults;
use App\Traits\ConfigBundle\ResolvesArtifactTargets;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class AnalyticsArtifactWriter implements ArtifactWriterInterface
{
    use ReportsArtifactWriteResults;
    use ResolvesArtifactTargets;

    public function __construct(
        private readonly ActingUserContext $actingUserContext,
        private readonly AddDashboardWidgetAction $addDashboardWidget,
        private readonly BundleWriteTenant $writeTenant,
        private readonly CreateAgingRuleAction $createAgingRule,
        private readonly CreateDashboardAction $createDashboard,
        private readonly CreateExportFieldPresetAction $createExportFieldPreset,
        private readonly CreateGoalAction $createGoal,
        private readonly CreateImportMappingPresetAction $createImportMappingPreset,
        private readonly CreateReportAction $createReport,
        private readonly CreateSegmentAction $createSegment,
        private readonly CreateSkillAction $createSkill,
        private readonly DeleteAgingRuleAction $deleteAgingRule,
        private readonly DeleteDashboardAction $deleteDashboard,
        private readonly DeleteExportFieldPresetAction $deleteExportFieldPreset,
        private readonly DeleteGoalAction $deleteGoal,
        private readonly DeleteImportMappingPresetAction $deleteImportMappingPreset,
        private readonly DeleteReportAction $deleteReport,
        private readonly DeleteSegmentAction $deleteSegment,
        private readonly DeleteSkillAction $deleteSkill,
        private readonly RemoveDashboardWidgetAction $removeDashboardWidget,
        private readonly TargetKeyResolver $targetKeys,
        private readonly UpdateAgingRuleAction $updateAgingRule,
        private readonly UpdateDashboardAction $updateDashboard,
        private readonly UpdateDashboardWidgetAction $updateDashboardWidget,
        private readonly UpdateExportFieldPresetAction $updateExportFieldPreset,
        private readonly UpdateGoalAction $updateGoal,
        private readonly UpdateImportMappingPresetAction $updateImportMappingPreset,
        private readonly UpdateReportAction $updateReport,
        private readonly UpdateSegmentAction $updateSegment,
        private readonly UpdateSkillAction $updateSkill,
    ) {}

    public function supports(ArtifactKind $kind): bool
    {
        return match ($kind) {
            ArtifactKind::Reports,
            ArtifactKind::Dashboards,
            ArtifactKind::DashboardWidgets,
            ArtifactKind::Goals,
            ArtifactKind::Segments,
            ArtifactKind::ExportFieldPresets,
            ArtifactKind::ImportMappingPresets,
            ArtifactKind::AgingRules,
            ArtifactKind::Skills => true,
            default => false,
        };
    }

    /**
     * @throws AuthorizationException
     */
    public function apply(User $actingUser, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        return $this->runAs($actingUser, fn (User $actor): ArtifactWriteResult => $this->guarded(
            $kind,
            $artifact->key,
            fn (): ArtifactWriteResult => $this->applied($actor, $kind, $artifact, $state),
        ));
    }

    /**
     * @throws AuthorizationException
     */
    public function remove(User $actingUser, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->runAs($actingUser, fn (User $actor): ArtifactWriteResult => $this->guarded(
            $kind,
            $key,
            fn (): ArtifactWriteResult => $this->removed($actor, $kind, $key),
        ));
    }

    /**
     * @return list<ArtifactWriteResult>
     */
    public function flush(User $actingUser): array
    {
        foreach ($this->carriedKinds() as $kind) {
            $this->targetKeys->invalidate($kind);
        }

        return [];
    }

    /**
     * @return list<ArtifactKind>
     */
    private function carriedKinds(): array
    {
        return array_values(array_filter(ArtifactKind::cases(), $this->supports(...)));
    }

    /**
     * @param  Closure(User): ArtifactWriteResult  $work
     *
     * @throws AuthorizationException
     */
    private function runAs(User $actingUser, Closure $work): ArtifactWriteResult
    {
        $tenantId = $this->writeTenant->tenantIdFor($actingUser);

        if ($tenantId === null) {
            throw new AuthorizationException(__('i18n.backend.handlers.config_bundle.analytics_artifact_writer.bundle_artifacts_can_only_be_written_by_an_acting'));
        }

        /** @var list<ArtifactWriteResult> $results */
        $results = [];

        $this->actingUserContext->run($tenantId, (string) $actingUser->getKey(), static function (User $actor) use ($work, &$results): void {
            $results[] = $work($actor);
        });

        return $results[0] ?? throw new AuthorizationException(__('i18n.backend.handlers.config_bundle.analytics_artifact_writer.bundle_artifacts_can_only_be_written_inside_an_acting'));
    }

    /**
     * @param  Closure(): ArtifactWriteResult  $work
     */
    private function guarded(ArtifactKind $kind, string $key, Closure $work): ArtifactWriteResult
    {
        try {
            return $work();
        } catch (ValidationException $exception) {
            return $this->skipped($kind, $key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_target_tenant_rejected_this_artifact').implode(' ', $exception->validator->errors()->all()));
        } catch (ModelNotFoundException) {
            return $this->skipped($kind, $key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.a_reference_in_this_artifact_does_not_identify_an'));
        } catch (AuthorizationException) {
            return $this->skipped($kind, $key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_acting_user_may_not_write_this_artifact_in'));
        } catch (InvalidFilterTreeException) {
            return $this->skipped($kind, $key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_filter_definition_specifies_a_filter_the_target_tenant'));
        }
    }

    private function applied(User $actor, ArtifactKind $kind, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        if ($state !== DiffState::Added && $state !== DiffState::Modified) {
            return $this->skippedByState($kind, $artifact->key, $state);
        }

        if ($this->carriesPlaceholder($artifact->payload)) {
            return $this->skippedByPlaceholder($kind, $artifact->key);
        }

        $result = match ($kind) {
            ArtifactKind::Reports => $this->applyReport($actor, $artifact, $state),
            ArtifactKind::Dashboards => $this->applyDashboard($actor, $artifact, $state),
            ArtifactKind::DashboardWidgets => $this->applyWidget($actor, $artifact, $state),
            ArtifactKind::Goals => $this->applyGoal($actor, $artifact, $state),
            ArtifactKind::Segments => $this->applySegment($actor, $artifact, $state),
            ArtifactKind::ExportFieldPresets => $this->applyExportFieldPreset($actor, $artifact, $state),
            ArtifactKind::ImportMappingPresets => $this->applyImportMappingPreset($actor, $artifact, $state),
            ArtifactKind::AgingRules => $this->applyAgingRule($artifact, $state),
            ArtifactKind::Skills => $this->applySkill($actor, $artifact, $state),
            default => $this->foreignKind($kind, $artifact->key),
        };

        return $this->invalidated($kind, $result);
    }

    private function removed(User $actor, ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        $result = match ($kind) {
            ArtifactKind::Reports => $this->removeReport($actor, $key),
            ArtifactKind::Dashboards => $this->removeDashboard($actor, $key),
            ArtifactKind::DashboardWidgets => $this->removeWidget($actor, $key),
            ArtifactKind::Goals => $this->removeGoal($actor, $key),
            ArtifactKind::Segments => $this->removeSegment($actor, $key),
            ArtifactKind::ExportFieldPresets => $this->removeExportFieldPreset($key),
            ArtifactKind::ImportMappingPresets => $this->removeImportMappingPreset($key),
            ArtifactKind::AgingRules => $this->removeAgingRule($key),
            ArtifactKind::Skills => $this->removeSkill($key),
            default => $this->foreignKind($kind, $key),
        };

        return $this->invalidated($kind, $result);
    }

    private function applyReport(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::Reports;
        $payload = $artifact->payload;
        $objectTypeId = $this->targetKeys->parentIdFor($kind, $artifact->key, 'object_type_id');
        $name = $this->suffixAfterPrefixOf(ArtifactKind::ObjectTypes, $artifact->key);

        if ($objectTypeId === null || $name === null) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        if ($this->textOf($payload, 'execution_mode') === ReportExecutionMode::Definer->value && !$actor->isEscalatedAuthority()) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_execution_mode_requires_an_elevated_role_for_the'));
        }

        $input = [
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'filter_definition' => $payload['filter_definition'] ?? [],
            'aggregation_type' => $payload['aggregation_type'] ?? null,
            'aggregation_field_key' => $payload['aggregation_field_key'] ?? null,
            'group_by_field_key' => $payload['group_by_field_key'] ?? null,
            'group_by_bucket' => $payload['group_by_bucket'] ?? null,
            'series_field_key' => $payload['series_field_key'] ?? null,
            'chart_type' => $payload['chart_type'] ?? null,
            'execution_mode' => $payload['execution_mode'] ?? null,
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            Report::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.report'),
            fn (): Report => $this->createReport->execute($actor, ['object_type_id' => $objectTypeId, ...$input]),
            fn (Report $report): Report => $this->updateReport->execute($actor, $report, $input),
            [],
            true,
        );
    }

    private function removeReport(User $actor, string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::Reports,
            $key,
            Report::class,
            function (Report $report) use ($actor): void {
                $this->deleteReport->execute($actor, $report);
            },
        );
    }

    private function applyDashboard(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::Dashboards;
        $payload = $artifact->payload;

        if (!$actor->isEscalatedAuthority()) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.a_bundled_dashboard_is_always_visible_tenant_wide_and'));
        }

        $input = [
            'name' => $artifact->key,
            'description' => $payload['description'] ?? null,
            'is_tenant_wide' => $payload['is_tenant_wide'] ?? true,
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            Dashboard::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.dashboard'),
            fn (): Dashboard => $this->createDashboard->execute($actor, $input),
            fn (Dashboard $dashboard): Dashboard => $this->updateDashboard->execute($actor, $dashboard, $input),
            [],
            true,
        );
    }

    private function removeDashboard(User $actor, string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::Dashboards,
            $key,
            Dashboard::class,
            function (Dashboard $dashboard) use ($actor): void {
                $this->deleteDashboard->execute($actor, $dashboard);
            },
        );
    }

    private function applyWidget(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::DashboardWidgets;
        $payload = $artifact->payload;
        $dashboardId = $this->targetKeys->parentIdFor($kind, $artifact->key, 'dashboard_id');
        $dashboard = $dashboardId === null ? null : Dashboard::query()->whereKey($dashboardId)->first();

        if (!$dashboard instanceof Dashboard) {
            return $this->unresolvable($kind, $artifact->key, 'dashboard_id', $artifact->key);
        }

        $reportKey = $this->textOf($payload, 'report_id');
        $goalKey = $this->textOf($payload, 'goal_id');
        $definition = is_array($payload['definition'] ?? null) ? $payload['definition'] : null;
        $named = array_filter([$reportKey, $goalKey, $definition === null ? null : 'definition']);

        if (count($named) !== 1) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.a_tile_shows_either_a_saved_report_a_goal'));
        }

        $input = [
            'title' => $payload['title'] ?? null,
            'column_span' => $payload['column_span'] ?? 1,
        ];

        if ($reportKey !== null) {
            $reportId = $this->targetKeys->idFor(ArtifactKind::Reports, $reportKey);

            if ($reportId === null) {
                return $this->unresolvable($kind, $artifact->key, 'report_id', $reportKey);
            }

            $input['report_id'] = $reportId;
        }

        if ($goalKey !== null) {
            $goalId = $this->targetKeys->idFor(ArtifactKind::Goals, $goalKey);

            if ($goalId === null) {
                return $this->unresolvable($kind, $artifact->key, 'goal_id', $goalKey);
            }

            $input['goal_id'] = $goalId;
        }

        if ($definition !== null) {
            $objectTypeKey = $this->textOf($definition, 'object_type_id');
            $objectTypeId = $objectTypeKey === null ? null : $this->targetKeys->idFor(ArtifactKind::ObjectTypes, $objectTypeKey);

            if ($objectTypeId === null) {
                return $this->unresolvable($kind, $artifact->key, 'definition.object_type_id', $objectTypeKey ?? $artifact->key);
            }

            $input['object_type_id'] = $objectTypeId;

            foreach (ReportInputRules::definitionKeys() as $definitionKey) {
                $input[$definitionKey] = $definition[$definitionKey] ?? null;
            }
        }

        if ($goalKey === null) {
            $chartType = $this->textOf($payload, 'chart_type');

            if ($chartType === null) {
                return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.a_tile_without_a_goal_requires_chart_type_the'));
            }

            $input['chart_type'] = $chartType;
        }

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            DashboardWidget::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.tile'),
            fn (): DashboardWidget => $this->addDashboardWidget->execute($actor, $dashboard, $input),
            fn (DashboardWidget $widget): DashboardWidget => $this->updateDashboardWidget->execute($actor, $widget, $input),
            [$this->positionNote()],
        );
    }

    private function removeWidget(User $actor, string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::DashboardWidgets,
            $key,
            DashboardWidget::class,
            function (DashboardWidget $widget) use ($actor): void {
                $this->removeDashboardWidget->execute($actor, $widget);
            },
        );
    }

    private function applyGoal(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::Goals;
        $payload = $artifact->payload;

        if ($this->textOf($payload, 'scope_type') !== GoalScopeType::Tenant->value) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_scope_type_specifies_a_person_or_team_bundles'));
        }

        $reportId = $this->targetKeys->parentIdFor($kind, $artifact->key, 'report_id');
        $report = $reportId === null ? null : Report::query()->whereKey($reportId)->first();
        $name = $this->suffixAfterPrefixOf(ArtifactKind::Reports, $artifact->key);

        if (!$report instanceof Report || $name === null) {
            return $this->unresolvable($kind, $artifact->key, 'report_id', $artifact->key);
        }

        if ($report->group_by_field_key !== null || $report->series_field_key !== null) {
            return $this->skipped($kind, $artifact->key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.a_goal_requires_a_report_with_exactly_one_metric'));
        }

        $notes = [];

        if ($this->textOf($payload, 'scope_field_key') !== null) {
            $notes[] = __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.a_tenant_goal_applies_to_the_entire_tenant_scope');
        }

        $input = [
            'name' => $name,
            'report_id' => $reportId,
            'scope_type' => GoalScopeType::Tenant->value,
            'scope_field_key' => null,
            'includes_subteams' => $payload['includes_subteams'] ?? false,
            'period_field_key' => $payload['period_field_key'] ?? null,
            'period_type' => $payload['period_type'] ?? null,
            'direction' => $payload['direction'] ?? null,
            'target_value' => $payload['target_value'] ?? null,
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            Goal::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.goal'),
            fn (): Goal => $this->createGoal->execute($actor, $input),
            fn (Goal $goal): Goal => $this->updateGoal->execute($actor, $goal, $input),
            $notes,
            true,
        );
    }

    private function removeGoal(User $actor, string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::Goals,
            $key,
            Goal::class,
            function (Goal $goal) use ($actor): void {
                $this->deleteGoal->execute($actor, $goal);
            },
        );
    }

    private function applySegment(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::Segments;
        $payload = $artifact->payload;
        $objectTypeId = $this->targetKeys->parentIdFor($kind, $artifact->key, 'object_type_id');
        $name = $this->suffixAfterPrefixOf(ArtifactKind::ObjectTypes, $artifact->key);

        if ($objectTypeId === null || $name === null) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $notes = [];

        if (($payload['is_system'] ?? false) === true || ($payload['is_default'] ?? false) === true || ($payload['i18n_labels'] ?? null) !== null) {
            $notes[] = __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.segment_management_does_not_accept_is_system_is_default');
        }

        $input = [
            'name' => $name,
            'filter_definition' => $payload['filter_definition'] ?? [],
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            Segment::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.segment'),
            fn (): Segment => $this->createSegment->execute($actor, ['object_type_id' => $objectTypeId, ...$input]),
            fn (Segment $segment): Segment => $this->updateSegment->execute($actor, $segment, $input),
            $notes,
            true,
        );
    }

    private function removeSegment(User $actor, string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::Segments,
            $key,
            Segment::class,
            function (Segment $segment) use ($actor): void {
                $this->deleteSegment->execute($actor, $segment);
            },
        );
    }

    private function applyExportFieldPreset(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::ExportFieldPresets;
        $payload = $artifact->payload;
        $objectType = $this->objectTypeOf($kind, $artifact->key);
        $name = $this->suffixAfterPrefixOf(ArtifactKind::ObjectTypes, $artifact->key);

        if (!$objectType instanceof ObjectType || $name === null) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $input = [
            'name' => $name,
            'fields' => $payload['fields'] ?? [],
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            ExportFieldPreset::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.field_selection'),
            fn (): ExportFieldPreset => $this->createExportFieldPreset->execute($objectType, $actor, $input),
            fn (ExportFieldPreset $preset): ExportFieldPreset => $this->updateExportFieldPreset->execute($preset, $input),
        );
    }

    private function removeExportFieldPreset(string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::ExportFieldPresets,
            $key,
            ExportFieldPreset::class,
            function (ExportFieldPreset $preset): void {
                $this->deleteExportFieldPreset->execute($preset);
            },
        );
    }

    private function applyImportMappingPreset(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::ImportMappingPresets;
        $payload = $artifact->payload;
        $objectType = $this->objectTypeOf($kind, $artifact->key);
        $name = $this->suffixAfterPrefixOf(ArtifactKind::ObjectTypes, $artifact->key);

        if (!$objectType instanceof ObjectType || $name === null) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $input = [
            'name' => $name,
            'mapping' => $payload['mapping'] ?? [],
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            ImportMappingPreset::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.assignment'),
            fn (): ImportMappingPreset => $this->createImportMappingPreset->execute($objectType, $actor, $input),
            fn (ImportMappingPreset $preset): ImportMappingPreset => $this->updateImportMappingPreset->execute($objectType, $actor, $preset, $input),
        );
    }

    private function removeImportMappingPreset(string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::ImportMappingPresets,
            $key,
            ImportMappingPreset::class,
            function (ImportMappingPreset $preset): void {
                $this->deleteImportMappingPreset->execute($preset);
            },
        );
    }

    private function applyAgingRule(BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $kind = ArtifactKind::AgingRules;
        $payload = $artifact->payload;
        $objectType = $this->objectTypeOf($kind, $artifact->key);
        $name = $this->suffixAfterPrefixOf(ArtifactKind::ObjectTypes, $artifact->key);

        if (!$objectType instanceof ObjectType || $name === null) {
            return $this->unresolvable($kind, $artifact->key, 'object_type_id', $artifact->key);
        }

        $input = [
            'name' => $name,
            'clock' => $payload['clock'] ?? null,
            'clock_field_key' => $payload['clock_field_key'] ?? null,
            'condition' => $payload['condition'] ?? null,
            'thresholds' => $payload['thresholds'] ?? [],
            'is_active' => $payload['is_active'] ?? false,
            'triggers_automation' => $payload['triggers_automation'] ?? false,
        ];

        return $this->upsert(
            $kind,
            $artifact->key,
            $state,
            AgingRule::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.aging_rule'),
            fn (): AgingRule => $this->createAgingRule->execute($objectType, $input),
            fn (AgingRule $rule): AgingRule => $this->updateAgingRule->execute($rule, $input),
        );
    }

    private function removeAgingRule(string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::AgingRules,
            $key,
            AgingRule::class,
            function (AgingRule $rule): void {
                $this->deleteAgingRule->execute($rule);
            },
        );
    }

    private function applySkill(User $actor, BundleArtifact $artifact, DiffState $state): ArtifactWriteResult
    {
        $input = ['name' => $artifact->key];

        return $this->upsert(
            ArtifactKind::Skills,
            $artifact->key,
            $state,
            Skill::class,
            __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.skill'),
            fn (): Skill => $this->createSkill->execute($actor, $input),
            fn (Skill $skill): Skill => $this->updateSkill->execute($skill, $input),
        );
    }

    private function removeSkill(string $key): ArtifactWriteResult
    {
        return $this->removeVia(
            ArtifactKind::Skills,
            $key,
            Skill::class,
            function (Skill $skill): void {
                $this->deleteSkill->execute($skill);
            },
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(): TModel  $create
     * @param  Closure(TModel): TModel  $update
     * @param  list<string>  $notes
     */
    private function upsert(
        ArtifactKind $kind,
        string $key,
        DiffState $state,
        string $model,
        string $subject,
        Closure $create,
        Closure $update,
        array $notes = [],
        bool $owned = false,
    ): ArtifactWriteResult {
        $existingId = $this->targetKeys->idFor($kind, $key);

        if ($existingId === null) {
            if ($state === DiffState::Modified) {
                return $this->missingTarget($kind, $key);
            }

            return $this->written($kind, $key, ArtifactWriteAction::Created, $create(), $owned ? [
                ...$notes,
                $this->ownershipNote($subject),
            ] : $notes);
        }

        $target = $model::query()->whereKey($existingId)->first();

        if (!$target instanceof $model) {
            return $this->missingTarget($kind, $key);
        }

        return $this->written($kind, $key, ArtifactWriteAction::Updated, $update($target), [
            ...$notes,
            ...$this->fallbackNotes($state, $subject),
        ]);
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(TModel): void  $delete
     */
    private function removeVia(ArtifactKind $kind, string $key, string $model, Closure $delete): ArtifactWriteResult
    {
        $id = $this->targetKeys->idFor($kind, $key);
        $target = $id === null ? null : $model::query()->whereKey($id)->first();

        if (!$target instanceof $model) {
            return $this->missingTarget($kind, $key);
        }

        $delete($target);

        return $this->written($kind, $key, ArtifactWriteAction::Removed, $target);
    }

    private function foreignKind(ArtifactKind $kind, string $key): ArtifactWriteResult
    {
        return $this->skipped($kind, $key, __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.this_artifact_type_is_not_a_report_or_template'));
    }

    private function ownershipNote(string $subject): string
    {
        return __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_entry_of_kind_now_belongs_to_you_in', ['value1' => $subject]);
    }

    private function positionNote(): string
    {
        return __('i18n.backend.handlers.config_bundle.analytics_artifact_writer.the_bundle_s_position_is_not_transferred_the_target');
    }

    private function suffixAfterPrefixOf(ArtifactKind $parent, string $businessKey): ?string
    {
        $prefix = $this->targetKeys->knownPrefixOf($parent, $businessKey);

        return $prefix === null ? null : mb_substr($businessKey, mb_strlen($prefix) + 1);
    }
}
