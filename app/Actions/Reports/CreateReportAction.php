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
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateReportAction
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
    public function execute(User $owner, array $input): Report
    {
        $validated = Validator::make($input, ReportInputRules::rules(true))->validate();

        $objectType = ObjectType::query()->whereKey($validated['object_type_id'])->firstOrFail();

        Gate::forUser($owner)->authorize('create', [Report::class, $objectType]);

        $mode = is_string($validated['execution_mode'] ?? null)
            ? ReportExecutionMode::from($validated['execution_mode'])
            : ReportExecutionMode::Viewer;

        if ($mode === ReportExecutionMode::Definer && !$owner->isEscalatedAuthority()) {
            throw ValidationException::withMessages([
                'execution_mode' => __('i18n.backend.actions.reports.create_report_action.only_an_elevated_role_may_create_a_report_that'),
            ]);
        }

        try {
            $definition = $this->definitionValidator->validate($validated, $objectType, $owner);
        } catch (ReportNotExecutableException $exception) {
            throw ReportInputRules::toValidationException($exception, $validated);
        }

        $tenantId = TenantContext::currentId((string) $owner->tenant_id);

        $report = DB::transaction(function () use ($owner, $tenantId, $objectType, $validated, $mode): Report {
            $report = Report::query()->create(array_merge([
                'tenant_id' => $tenantId,
                'owner_id' => $owner->getKey(),
                'object_type_id' => $objectType->getKey(),
            ], ReportInputRules::attributes($validated, $mode)));

            if ($mode === ReportExecutionMode::Definer) {
                $this->auditor->record(
                    $report,
                    ['execution_mode' => null],
                    ['execution_mode' => $mode->value],
                );
            }

            return $report;
        });

        $this->indexStarter->start($definition, (string) $report->getKey());

        return $report;
    }
}
