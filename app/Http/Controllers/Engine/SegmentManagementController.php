<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Segments\BulkDeleteSegmentsAction;
use App\Actions\Segments\CreateSegmentAction;
use App\Actions\Segments\DeleteSegmentAction;
use App\Actions\Segments\UpdateSegmentAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\FieldDefinitionResource;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\User;
use App\Support\Segments\ManageableSegmentResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class SegmentManagementController extends Controller
{
    public function __construct(
        private readonly CreateSegmentAction $createSegment,
        private readonly UpdateSegmentAction $updateSegment,
        private readonly DeleteSegmentAction $deleteSegment,
        private readonly BulkDeleteSegmentsAction $bulkDeleteSegments,
        private readonly ManageableSegmentResolver $manageableSegments,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->actingUser($request);

        $names = ObjectType::query()->pluck('name', 'id');

        $segments = Segment::query()
            ->where('is_system', false)
            ->orderBy('name')
            ->get()
            ->filter(fn (Segment $segment): bool => $user->can('update', $segment))
            ->map(fn (Segment $segment): array => $this->indexPayload($segment, $names, $user))
            ->values()
            ->all();

        return Inertia::render('segments/Index', [
            'segments' => $segments,
        ]);
    }

    public function create(Request $request): Response
    {
        $this->actingUser($request);

        $types = ObjectType::query()->generic()->orderBy('name')->get();

        return Inertia::render('segments/Form', [
            'mode' => 'create',
            'segment' => null,
            'objectTypeOptions' => $this->objectTypeOptions($types),
            'fieldsByType' => $this->fieldsByType($types),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->actingUser($request);

        $this->createSegment->execute($user, $request->all());

        return to_route('engine.segments.manage.index');
    }

    public function edit(Request $request, string $segment): Response
    {
        $segment = $this->manageableSegments->resolveAuthorized($this->actingUser($request), $segment, 'update');

        $types = $segment->object_type_id !== null
            ? ObjectType::query()->whereKey($segment->object_type_id)->get()
            : new EloquentCollection;

        return Inertia::render('segments/Form', [
            'mode' => 'edit',
            'segment' => $this->formPayload($segment),
            'objectTypeOptions' => $this->objectTypeOptions($types),
            'fieldsByType' => $this->fieldsByType($types),
        ]);
    }

    public function update(Request $request, string $segment): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->where('tenant_id', $user->tenant_id)->whereKey($segment)->firstOrFail();

        $this->updateSegment->execute($user, $model, $request->all());

        return to_route('engine.segments.manage.index');
    }

    public function destroy(Request $request, string $segment): RedirectResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->where('tenant_id', $user->tenant_id)->whereKey($segment)->firstOrFail();

        $this->deleteSegment->execute($user, $model);

        return to_route('engine.segments.manage.index');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->bulkDeleteSegments->execute($this->actingUser($request), $request->all());

        return to_route('engine.segments.manage.index');
    }

    /**
     * @param  Collection<string, string>  $names
     * @return array<string, mixed>
     */
    private function indexPayload(Segment $segment, Collection $names, User $user): array
    {
        return [
            'id' => (string) $segment->getKey(),
            'name' => $segment->name,
            'object_type' => $segment->object_type_id !== null
                ? ($names[$segment->object_type_id] ?? null)
                : null,
            'is_owner' => $segment->owner_id === $user->getKey(),
            'can_update' => $user->can('update', $segment),
            'can_delete' => $user->can('delete', $segment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formPayload(Segment $segment): array
    {
        return [
            'id' => (string) $segment->getKey(),
            'name' => $segment->name,
            'object_type_id' => $segment->object_type_id,
            'filter_definition' => $segment->filter_definition,
        ];
    }

    /**
     * @param  EloquentCollection<int, ObjectType>  $types
     * @return array<int, array{value: string, label: string}>
     */
    private function objectTypeOptions(EloquentCollection $types): array
    {
        return $types
            ->map(fn (ObjectType $type): array => ['value' => (string) $type->getKey(), 'label' => $type->name])
            ->values()
            ->all();
    }

    /**
     * @param  EloquentCollection<int, ObjectType>  $types
     * @return array<string, array<int, mixed>>
     */
    private function fieldsByType(EloquentCollection $types): array
    {
        $fields = FieldDefinition::query()
            ->whereIn('object_type_id', $types->modelKeys())
            ->where('is_filterable', true)
            ->orderByRaw('list_position is null, list_position')
            ->get()
            ->groupBy('object_type_id');

        $map = [];

        foreach ($types as $type) {
            $typeId = (string) $type->getKey();
            $map[$typeId] = FieldDefinitionResource::collection($fields->get($typeId, collect()))->resolve();
        }

        return $map;
    }
}
