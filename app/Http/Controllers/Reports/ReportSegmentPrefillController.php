<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\Report;
use App\Models\Segment;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\SystemFilterFields;
use App\Support\Segments\SystemSegmentDescriptor;
use App\Support\Segments\SystemSegmentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReportSegmentPrefillController extends Controller
{
    public function __construct(
        private readonly SystemSegmentRegistry $systemSegments,
        private readonly FilterFieldKeyCollector $filterFieldKeys,
        private readonly SystemFilterFields $systemFilterFields,
        private readonly FieldVisibilityResolver $fieldVisibility,
    ) {}

    public function __invoke(Request $request, string $segment): JsonResponse
    {
        $this->authorize('viewAny', Report::class);

        $user = $this->actingUser($request);

        if ($this->systemSegments->find($segment) instanceof SystemSegmentDescriptor) {
            throw ValidationException::withMessages([
                'segment' => __('i18n.backend.http.controllers.reports.report_segment_prefill_controller.system_segments_cannot_be_used_as_report_templates'),
            ]);
        }

        $model = Segment::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKey($segment)
            ->firstOrFail();

        if ($user->cannot('view', $model)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.reports.report_segment_prefill_controller.you_may_not_view_this_segment'));
        }

        $filterDefinition = $model->filter_definition ?? [];

        if ($this->namesAnAgingField($filterDefinition)) {
            throw ValidationException::withMessages([
                'segment' => __('i18n.backend.http.controllers.reports.report_segment_prefill_controller.segments_filtering_by_aging_cannot_be_used_as_report'),
            ]);
        }

        if ($this->namesAForbiddenField($user, $model, $filterDefinition)) {
            throw ValidationException::withMessages([
                'segment' => __('i18n.backend.http.controllers.reports.report_segment_prefill_controller.segments_filtering_by_fields_you_may_not_view_cannot_be_used'),
            ]);
        }

        return new JsonResponse([
            'data' => [
                'object_type_id' => $model->object_type_id === null ? null : $model->object_type_id,
                'filter_definition' => $filterDefinition,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filterDefinition
     */
    private function namesAForbiddenField(User $user, Segment $segment, array $filterDefinition): bool
    {
        if ($segment->object_type_id === null) {
            return false;
        }

        $forbidden = $this->fieldVisibility->forbiddenReadFieldKeys($user, $segment->object_type_id);

        return array_intersect($this->filterFieldKeys->collect($filterDefinition), $forbidden) !== [];
    }

    /**
     * @param  array<string, mixed>  $filterDefinition
     */
    private function namesAnAgingField(array $filterDefinition): bool
    {
        foreach ($this->filterFieldKeys->collect($filterDefinition) as $key) {
            if ($this->systemFilterFields->isAgingField($key)) {
                return true;
            }
        }

        return false;
    }
}
