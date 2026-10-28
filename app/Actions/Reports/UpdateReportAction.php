<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Enums\Reports\ReportExecutionMode;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Audit\AdminArtifactAuditor;
use App\Support\Reports\EnsureReportIndexesStarter;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportInputRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateReportAction
{
    public function __construct(
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly AdminArtifactAuditor $auditor,
        private readonly EnsureReportIndexesStarter $indexStarter,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(User $actor, Report $report, array $input): Report
    {
        Gate::forUser($actor)->authorize('update', $report);

        $validated = Validator::make($input, ReportInputRules::rules(false))->validate();

        $previousMode = $report->execution_mode;

        $mode = is_string($validated['execution_mode'] ?? null)
            ? ReportExecutionMode::from($validated['execution_mode'])
            : $previousMode;

        if ($mode !== $previousMode && $mode === ReportExecutionMode::Definer && !$actor->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'execution_mode' => __('i18n.backend.actions.reports.update_report_action.only_an_elevated_role_may_switch_a_report_to'),
            ]);
        }

        $objectType = ObjectType::query()->whereKey($report->object_type_id)->firstOrFail();

        try {
            $definition = $this->definitionValidator->validate($validated, $objectType, $actor);
        } catch (ReportNotExecutableException $exception) {
            throw ReportInputRules::toValidationException($exception, $validated);
        }

        $saved = DB::transaction(function () use ($report, $validated, $mode, $previousMode): Report {
            $report->fill(ReportInputRules::attributes($validated, $mode));

            $report->save();

            if ($mode !== $previousMode) {
                $this->auditor->record(
                    $report,
                    ['execution_mode' => $previousMode->value],
                    ['execution_mode' => $mode->value],
                );
            }

            return $report;
        });

        $this->indexStarter->start($definition, (string) $saved->getKey());

        return $saved;
    }
}
