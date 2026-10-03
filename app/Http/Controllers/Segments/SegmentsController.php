<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Actions\Segments\CreateSegmentAction;
use App\Actions\Segments\DeleteSegmentAction;
use App\Actions\Segments\UpdateSegmentAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\I18n\TranslatableValueResolver;
use App\Support\Segments\SegmentResolver;
use App\Support\Segments\SystemSegmentDescriptor;
use App\Support\Segments\SystemSegmentRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentsController extends Controller
{
    public function __construct(
        private readonly SegmentResolver $segmentResolver,
        private readonly SystemSegmentRegistry $systemSegmentRegistry,
        private readonly CreateSegmentAction $createSegment,
        private readonly UpdateSegmentAction $updateSegment,
        private readonly DeleteSegmentAction $deleteSegment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        $objectTypeId = $this->resolveObjectTypeId($request);

        $segments = Segment::query()
            ->when(
                $objectTypeId !== null,
                fn ($query) => $query->where(fn ($scoped) => $scoped
                    ->whereNull('object_type_id')
                    ->orWhere('object_type_id', $objectTypeId)),
            )
            ->get()
            ->filter(fn (Segment $segment): bool => $user->can('view', $segment))
            ->map(fn (Segment $segment): array => $this->present($segment, $user))
            ->values()
            ->all();

        $systemSegments = collect($this->systemSegmentRegistry->all())
            ->map(fn (SystemSegmentDescriptor $descriptor): Segment => $descriptor->toSegment($user))
            ->filter(fn (Segment $segment): bool => $objectTypeId === null
                || $segment->object_type_id === null
                || $segment->object_type_id === $objectTypeId)
            ->filter(fn (Segment $segment): bool => $user->can('view', $segment))
            ->map(fn (Segment $segment): array => $this->present($segment, $user))
            ->values()
            ->all();

        return new JsonResponse(['data' => array_merge($segments, $systemSegments)]);
    }

    private function resolveObjectTypeId(Request $request): ?string
    {
        $slug = $request->query('object_type');

        if (!is_string($slug) || $slug === '') {
            return null;
        }

        $id = ObjectType::query()->where('slug', $slug)->value('id');

        return is_string($id) ? $id : null;
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        $segment = $this->createSegment->execute($user, $request->all());

        return new JsonResponse(['data' => $this->present($segment, $user)], 201);
    }

    public function show(Request $request, string $segment): JsonResponse
    {
        $user = $this->actingUser($request);

        $descriptor = $this->systemSegmentRegistry->find($segment);

        if ($descriptor !== null) {
            return new JsonResponse([
                'sections' => $this->enrichSections(
                    $this->segmentResolver->resolve($descriptor->toSegment($user), $user, $descriptor),
                    $user,
                ),
            ]);
        }

        $model = Segment::query()->whereKey($segment)->firstOrFail();

        if ($user->cannot('view', $model)) {
            throw new AuthorizationException(__('i18n.backend.http.controllers.segments.segments_controller.you_may_not_view_this_segment'));
        }

        return new JsonResponse([
            'sections' => $this->enrichSections($this->segmentResolver->resolve($model, $user), $user),
        ]);
    }

    /**
     * @param  list<array{objectTypeId: string, slug: string, records: array<int, array<string, mixed>>}>  $sections
     * @return list<array{objectTypeId: string, slug: string, name: string, columns: list<array{key: string, label: string}>, records: array<int, array<string, mixed>>}>
     */
    private function enrichSections(array $sections, User $user): array
    {
        if ($sections === []) {
            return [];
        }

        $typeIds = array_map(static fn (array $section): string => $section['objectTypeId'], $sections);
        $types = ObjectType::query()->whereKey($typeIds)->get()->keyBy('id');
        $resolver = FieldVisibilityResolver::forRequest();

        return array_map(function (array $section) use ($types, $resolver, $user): array {
            $objectTypeId = $section['objectTypeId'];
            $type = $types->get($objectTypeId);

            return [
                'objectTypeId' => $objectTypeId,
                'slug' => $section['slug'],
                'name' => $type instanceof ObjectType ? $type->name : $section['slug'],
                'columns' => $this->readableColumns($objectTypeId, $user, $resolver),
                'records' => $section['records'],
            ];
        }, $sections);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function readableColumns(string $objectTypeId, User $user, FieldVisibilityResolver $resolver): array
    {
        $readable = $resolver->readableFieldKeys($user, $objectTypeId);

        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->orderByRaw('list_position is null, list_position')
            ->get()
            ->filter(static fn (FieldDefinition $field): bool => !$field->is_encrypted && in_array($field->key, $readable, true))
            ->values();

        $defaults = $fields->filter(static fn (FieldDefinition $field): bool => $field->is_default_column)->values();

        return array_values(
            ($defaults->isNotEmpty() ? $defaults : $fields)
                ->map(fn (FieldDefinition $field): array => [
                    'key' => $field->key,
                    'label' => $this->columnLabel($field),
                ])
                ->all(),
        );
    }

    private function columnLabel(FieldDefinition $field): string
    {
        $label = (new TranslatableValueResolver)->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }

    public function update(Request $request, string $segment): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->whereKey($segment)->firstOrFail();

        $this->updateSegment->execute($user, $model, $request->all());

        return new JsonResponse(['data' => $this->present($model->refresh(), $user)]);
    }

    public function destroy(Request $request, string $segment): JsonResponse
    {
        $user = $this->actingUser($request);
        $model = Segment::query()->whereKey($segment)->firstOrFail();

        $this->deleteSegment->execute($user, $model);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Segment $segment, User $user): array
    {
        return [
            'id' => (string) $segment->getKey(),
            'name' => $segment->name,
            'object_type_id' => $segment->object_type_id,
            'is_system' => $segment->is_system,
            'is_default' => $segment->is_default,
            'is_owner' => $segment->owner_id === $user->getKey(),
        ];
    }
}
