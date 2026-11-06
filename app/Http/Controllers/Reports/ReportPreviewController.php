<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\DTOs\Reports\ReportResultData;
use App\Enums\Reports\ReportExecutionMode;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Reports\ReportResultResource;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportExecutionContext;
use App\Support\Reports\ReportExecutionModeResolver;
use App\Support\Reports\ReportInputRules;
use App\Support\Reports\ReportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class ReportPreviewController extends Controller
{
    public function __construct(
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly ReportRunner $runner,
        private readonly ReportExecutionContext $context,
        private readonly ReportExecutionModeResolver $modeResolver,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Report::class);

        $user = $this->actingUser($request);

        $validated = $request->validate([
            'report_id' => ['nullable', 'string'],
            'object_type_id' => ['required_without:report_id', 'nullable', 'string'],
            'filter_definition' => ['nullable', 'array'],
            'aggregation_type' => ['nullable', 'string'],
            'aggregation_field_key' => ['nullable', 'string'],
            'group_by_field_key' => ['nullable', 'string'],
            'group_by_bucket' => ['nullable', 'string'],
            'series_field_key' => ['nullable', 'string'],
        ]);

        $reportId = $validated['report_id'] ?? null;
        $report = is_string($reportId) && $reportId !== ''
            ? Report::query()->where('tenant_id', $user->tenant_id)->whereKey($reportId)->firstOrFail()
            : null;

        if ($report instanceof Report) {
            $this->authorize('view', $report);
        }

        $objectType = $report instanceof Report
            ? ObjectType::query()->whereKey($report->object_type_id)->firstOrFail()
            : ObjectType::query()->whereKey($validated['object_type_id'])->firstOrFail();

        if (!$report instanceof Report) {
            Gate::forUser($user)->authorize('create', [Report::class, $objectType]);
        }

        $definition = $report instanceof Report
            ? $this->savedDefinition($report)
            : $this->requestedDefinition($validated);

        $effectiveMode = $this->modeResolver->effectiveMode($report);

        $run = fn (User $actingUser): ReportResultData => $this->runner->run(
            $this->definitionValidator->validate($definition, $objectType, $actingUser),
        );

        $result = $effectiveMode === ReportExecutionMode::Definer && $report instanceof Report
            ? $this->context->runAsDefiner((string) $user->tenant_id, $report->owner_id, $run)
            : $this->context->runAsViewer((string) $user->tenant_id, (string) $user->getKey(), $run);

        return new JsonResponse([
            'data' => (new ReportResultResource($result, $effectiveMode))->resolve($request),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedDefinition(Report $report): array
    {
        return $report->only(ReportInputRules::definitionKeys());
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function requestedDefinition(array $validated): array
    {
        return Arr::only($validated, ReportInputRules::definitionKeys());
    }
}
