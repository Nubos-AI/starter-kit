<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Reports;

use App\DTOs\Reports\ReportResultData;
use App\Enums\Api\ApiAccessLevel;
use App\Enums\Api\ApiErrorCode;
use App\Enums\Reports\ReportExecutionMode;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Api\V1\ReportResource;
use App\Http\Resources\Api\V1\ReportResultResource;
use App\Models\ObjectType;
use App\Models\Report;
use App\Models\User;
use App\Support\Api\ApiAbilityMap;
use App\Support\Api\JsonApiErrorBag;
use App\Support\Reports\ReportDefinitionValidator;
use App\Support\Reports\ReportExecutionContext;
use App\Support\Reports\ReportExecutionModeResolver;
use App\Support\Reports\ReportInputRules;
use App\Support\Reports\ReportRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(
        private readonly ReportDefinitionValidator $definitionValidator,
        private readonly ReportRunner $runner,
        private readonly ReportExecutionContext $context,
        private readonly ReportExecutionModeResolver $modeResolver,
        private readonly ApiAbilityMap $abilityMap,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Report::class);

        $user = $this->actingUser($request);

        $reports = $this->tenantReports($user)
            ->orderBy('name')
            ->get()
            ->filter(fn (Report $report): bool => $user->can('view', $report)
                && $this->maySeeObjectType($request, $report))
            ->values();

        return new JsonResponse([
            'data' => ReportResource::collection($reports)->resolve($request),
        ]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveReport($user, $report);

        $this->authorize('view', $model);

        return new JsonResponse([
            'data' => (new ReportResource($model))->resolve($request),
        ]);
    }

    public function result(Request $request, string $report): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = $this->resolveReport($user, $report);

        $this->authorize('view', $model);

        $objectType = ObjectType::query()->whereKey($model->object_type_id)->firstOrFail();

        $effectiveMode = $this->modeResolver->effectiveMode($model);

        $run = fn (User $actingUser): ReportResultData => $this->runner->run(
            $this->definitionValidator->validate(
                $model->only(ReportInputRules::definitionKeys()),
                $objectType,
                $actingUser,
            ),
        );

        try {
            $result = $effectiveMode === ReportExecutionMode::Definer
                ? $this->context->runAsDefiner((string) $user->tenant_id, $model->owner_id, $run)
                : $this->context->runAsViewer((string) $user->tenant_id, (string) $user->getKey(), $run);
        } catch (ReportNotExecutableException $exception) {
            return JsonApiErrorBag::single(
                422,
                ApiErrorCode::ValidationFailed,
                $exception->getMessage(),
                null,
                ['reason' => $exception->reason->value],
            );
        }

        return new JsonResponse([
            'data' => (new ReportResultResource($result, $effectiveMode, (string) $model->getKey()))->resolve($request),
        ]);
    }

    private function resolveReport(User $user, string $report): Report
    {
        return $this->tenantReports($user)->whereKey($report)->firstOrFail();
    }

    /**
     * @return Builder<Report>
     */
    private function tenantReports(User $user): Builder
    {
        return Report::query()
            ->with('objectType')
            ->where('tenant_id', $user->tenant_id);
    }

    private function maySeeObjectType(Request $request, Report $report): bool
    {
        return $this->abilityMap->satisfiesObjectType(
            $request->user()?->currentAccessToken(),
            $report->objectType,
            ApiAccessLevel::Read,
        );
    }
}
