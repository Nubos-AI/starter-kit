<?php

declare(strict_types=1);

namespace App\Support\Segments;

use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordFilterCompiler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SegmentResolver
{
    public function __construct(
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly AgingEvaluator $agingEvaluator,
        private readonly SegmentFilterFieldSource $filterFields,
    ) {}

    /**
     * @return list<array{objectTypeId: string, slug: string, records: array<int, array<string, mixed>>}>
     *
     * @throws AuthorizationException
     */
    public function resolve(Segment $segment, User $viewer, ?SystemSegmentDescriptor $descriptor = null): array
    {
        $this->assertResolvableContext($viewer);

        if (!$viewer->can('view', $segment)) {
            throw new AuthorizationException(__('i18n.backend.support.segments.segment_resolver.you_may_not_view_this_view'));
        }

        $request = $this->viewerBoundRequest($viewer);
        $sections = [];

        foreach ($this->readableTypes($segment, $viewer) as $type) {
            $records = $this->recordsForType($segment, $type, $viewer, $descriptor);

            $sections[] = [
                'objectTypeId' => (string) $type->getKey(),
                'slug' => $type->slug,
                'records' => RecordResource::collection($records)->resolve($request),
            ];
        }

        return $sections;
    }

    /**
     * @throws AuthorizationException
     */
    private function assertResolvableContext(User $viewer): void
    {
        $authenticated = Auth::user();

        if (!$authenticated instanceof User || !$authenticated->is($viewer) || !app()->bound('current_tenant')) {
            throw new AuthorizationException(__('i18n.backend.support.segments.segment_resolver.segment_resolution_requires_the_acting_viewer_within_a_bound'));
        }
    }

    /**
     * @return EloquentCollection<int, ObjectType>
     */
    private function readableTypes(Segment $segment, User $viewer): EloquentCollection
    {
        return $this->candidateTypes($segment)
            ->filter(fn (ObjectType $type): bool => $viewer->hasPermission("{$type->slug}.view"))
            ->values();
    }

    /**
     * @return EloquentCollection<int, ObjectType>
     */
    private function candidateTypes(Segment $segment): EloquentCollection
    {
        if ($segment->object_type_id !== null) {
            return ObjectType::query()->whereKey($segment->object_type_id)->get();
        }

        return ObjectType::query()->get();
    }

    /**
     * @return EloquentCollection<int, CustomRecord>
     */
    private function recordsForType(Segment $segment, ObjectType $type, User $viewer, ?SystemSegmentDescriptor $descriptor = null): EloquentCollection
    {
        $query = CustomRecord::query()->ofType($type);

        $this->applyScope(
            $query,
            $segment,
            $type,
            $viewer,
            $descriptor,
            $this->agingEvaluator->expressions($type, $this->readableFieldSet($type, $viewer)),
        );

        if ($descriptor?->sort !== null) {
            $query->orderBy($descriptor->sort['column'], $descriptor->sort['direction']);
        }

        return $query->get();
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     */
    public function applyScope(
        Builder $query,
        Segment $segment,
        ObjectType $type,
        User $viewer,
        ?SystemSegmentDescriptor $descriptor = null,
        array $virtualFieldExpressions = [],
    ): void {
        $this->filterCompiler->applyTree(
            $query,
            $this->filterFields->forObjectType($type),
            $segment->filter_definition ?? [],
            FieldVisibilityResolver::forRequest(),
            $type,
            $virtualFieldExpressions,
        );

        if ($descriptor !== null && $descriptor->ownerScoped) {
            $query->where('owner_id', $viewer->getKey());
        }
    }

    /**
     * @return EloquentCollection<int, FieldDefinition>
     */
    private function readableFieldSet(ObjectType $type, User $viewer): EloquentCollection
    {
        $forbidden = FieldVisibilityResolver::forRequest()
            ->forbiddenReadFieldKeys($viewer, (string) $type->getKey());

        return $type->fieldDefinitions
            ->filter(fn (FieldDefinition $field): bool => !$field->is_encrypted && !in_array($field->key, $forbidden, true))
            ->values();
    }

    private function viewerBoundRequest(User $viewer): Request
    {
        $request = Request::create('/');
        $request->setUserResolver(fn (): User => $viewer);

        return $request;
    }
}
