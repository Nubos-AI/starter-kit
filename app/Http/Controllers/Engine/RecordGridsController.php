<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\DTOs\Engine\RecordQueryScopeData;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Aging\AgingEvaluator;
use App\Support\Engine\InitialRecordGridRequest;
use App\Support\Engine\ObjectTypePresenter;
use App\Support\Engine\RecordQueryScope;
use App\Support\Engine\SystemFilterFields;
use App\Support\Modules\RecordExtensions;
use App\Support\Preferences\PreferenceSchema;
use App\Support\Preferences\UserPreferenceResolver;
use App\Support\Reports\ReportDrillDownInput;
use App\Support\Segments\DefaultSegmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RecordGridsController extends Controller
{
    private int $maxBlockSize = 1000;

    public function __construct(
        private readonly RecordQueryScope $queryScope,
        private readonly AgingEvaluator $agingEvaluator,
        private readonly SystemFilterFields $systemFields,
        private readonly UserPreferenceResolver $preferences,
        private readonly ObjectTypePresenter $objectTypePresenter,
        private readonly RecordExtensions $extensions,
        private readonly InitialRecordGridRequest $initialRequest,
        private readonly PreferenceSchema $preferenceSchema,
        private readonly DefaultSegmentResolver $defaultSegments,
        private readonly ReportDrillDownInput $drillDownInput,
    ) {}

    public function index(Request $request, ObjectType $objectType): Response
    {
        $objectType->load('fieldDefinitions');

        $defaultColumns = $objectType->fieldDefinitions
            ->where('is_default_column', true)
            ->sortBy('list_position')
            ->values();

        $columns = $defaultColumns->isNotEmpty()
            ? $defaultColumns
            : $objectType->fieldDefinitions
                ->reject(fn (FieldDefinition $field): bool => $field->is_encrypted)
                ->sortBy('list_position')
                ->values();

        $user = $this->actingUser($request);
        $document = $this->preferences->document($user);
        $preference = $document['objectTypes'][(string) $objectType->getKey()]
            ?? $this->preferenceSchema->objectTypeDefaults();
        $defaultSegmentId = $this->defaultSegments->idFor($objectType);
        $initialRequest = $this->initialRequest->resolve(
            $request,
            $objectType,
            $preference,
            $document['settings']['pageSize'] ?? 100,
            $defaultSegmentId,
        );

        return Inertia::render('records/Index', [
            'objectType' => $this->objectTypePresenter->summary($objectType),
            'preference' => $preference,
            'defaultSegmentId' => $defaultSegmentId,
            'initialGrid' => $initialRequest === null ? null : fn (): ?array => $this->initialGrid($request, $objectType, $initialRequest),
            'hasRecords' => CustomRecord::query()
                ->ofType($objectType)
                ->exists(),
            ...$this->extensions->index($objectType, $user),
            'columns' => FieldDefinitionResource::collection($columns)->resolve($request),
            'fieldDefinitions' => [
                ...FieldDefinitionResource::collection(
                    $objectType->fieldDefinitions->sortBy('id')->values(),
                )->resolve($request),
                ...$this->systemFields->payload($this->agingEvaluator->fields($objectType)),
            ],
        ]);
    }

    public function grid(Request $request, ObjectType $objectType): JsonResponse
    {
        $validated = $request->validate([
            'startRow' => ['required', 'integer', 'min:0'],
            'endRow' => ['required', 'integer', 'gt:startRow'],
            'hierarchy' => ['boolean'],
            'sortModel' => ['array'],
            'filterModel' => ['array'],
            'search' => ['nullable', 'string', 'max:255'],
            'segment' => ['nullable', 'string'],
            ...$this->drillDownInput->rules(),
        ]);

        return new JsonResponse($this->readGrid($request, $objectType, $validated));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function readGrid(Request $request, ObjectType $objectType, array $validated): array
    {
        $startRow = (int) $validated['startRow'];
        $blockSize = min((int) $validated['endRow'] - $startRow, $this->maxBlockSize);

        $objectType->loadMissing('fieldDefinitions');

        $query = CustomRecord::query()->ofType($objectType);

        $scope = $this->queryScope->apply($query, new RecordQueryScopeData(
            $objectType,
            $this->actingUser($request),
            $validated['segment'] ?? null,
            $this->drillDownInput->parse($validated, is_string($validated['segment'] ?? null) ? $validated['segment'] : null),
            is_array($validated['filterModel'] ?? null) ? $validated['filterModel'] : [],
            is_array($validated['sortModel'] ?? null) ? $validated['sortModel'] : [],
            (bool) ($validated['hierarchy'] ?? false),
            true,
            is_string($validated['search'] ?? null) ? $validated['search'] : null,
        ));

        $rows = $query->offset($startRow)->limit($blockSize)->get();

        $this->queryScope->attachRuleNames($rows);

        $lastRow = $rows->count() < $blockSize ? $startRow + $rows->count() : null;

        return [
            'rows' => RecordResource::collection($rows)->resolve($request),
            'lastRow' => $lastRow,
            'hierarchy' => $scope->hierarchy->payload(),
            'searchApplied' => $scope->searchApplied,
        ];
    }

    /**
     * @param  array<string, mixed>  $initialRequest
     * @return array<string, mixed>|null
     */
    private function initialGrid(Request $request, ObjectType $objectType, array $initialRequest): ?array
    {
        try {
            return [
                'request' => [...$initialRequest, 'filterModel' => (object) []],
                'response' => $this->readGrid($request, $objectType, $initialRequest),
            ];
        } catch (AuthorizationException|ModelNotFoundException|ValidationException) {
            return null;
        }
    }
}
