<?php

declare(strict_types=1);

namespace App\Http\Controllers\Segments;

use App\Actions\Segments\PersistFilterStateAction;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\FilterState;
use App\Models\ObjectType;
use App\Models\Segment;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\RecordFilterCompiler;
use App\Support\Engine\RecordSelectionResolver;
use App\Support\Segments\SegmentResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class SegmentDeepLinksController extends Controller
{
    private int $payloadGuard = 1_500;

    private string $shortIdPrefix = 'fs_';

    public function __construct(
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly RecordSelectionResolver $selectionResolver,
        private readonly SegmentResolver $segmentResolver,
        private readonly PersistFilterStateAction $persistFilterState,
    ) {}

    public function __invoke(Request $request, ObjectType $objectType): JsonResponse
    {
        $viewer = $this->actingUser($request);

        try {
            $segmentId = $request->query('segment');

            if (is_string($segmentId) && $segmentId !== '') {
                return $this->resolveSegment($segmentId, $viewer);
            }

            $filter = $request->query('filter');

            if (is_string($filter) && $filter !== '') {
                return $this->resolveFilter($filter, $objectType, $request, $viewer);
            }

            return new JsonResponse(['sections' => [], 'filter' => null]);
        } catch (AuthorizationException $exception) {
            return $this->rejected($exception->getMessage(), Response::HTTP_FORBIDDEN);
        } catch (ModelNotFoundException) {
            return $this->rejected(__('i18n.backend.http.controllers.segments.segment_deep_links_controller.the_requested_resource_was_not_found'), Response::HTTP_NOT_FOUND);
        } catch (InvalidFilterTreeException $exception) {
            return $this->rejected($exception->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    private function rejected(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status);
    }

    private function resolveSegment(string $segmentId, User $viewer): JsonResponse
    {
        $segment = Segment::query()->whereKey($segmentId)->firstOrFail();

        return new JsonResponse([
            'sections' => $this->segmentResolver->resolve($segment, $viewer),
            'filter' => null,
        ]);
    }

    private function resolveFilter(string $filter, ObjectType $objectType, Request $request, User $viewer): JsonResponse
    {
        if (str_starts_with($filter, $this->shortIdPrefix)) {
            $state = FilterState::query()
                ->whereKey(substr($filter, strlen($this->shortIdPrefix)))
                ->where('object_type_id', $objectType->getKey())
                ->firstOrFail();

            return new JsonResponse([
                'sections' => $this->filterSections($state->tree, $objectType, $request, $viewer),
                'filter' => $filter,
            ]);
        }

        $tree = $this->decodeFilter($filter);

        if (strlen($filter) > $this->payloadGuard) {
            $sections = $this->filterSections($tree, $objectType, $request, $viewer);
            $state = $this->persistFilterState->execute($tree, $objectType, $viewer);

            return new JsonResponse([
                'sections' => $sections,
                'filter' => $this->shortIdPrefix.$state->getKey(),
            ]);
        }

        return new JsonResponse([
            'sections' => $this->filterSections($tree, $objectType, $request, $viewer),
            'filter' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidFilterTreeException
     */
    private function decodeFilter(string $payload): array
    {
        $normalized = strtr($payload, '-_', '+/');
        $padded = str_pad($normalized, intdiv(strlen($normalized) + 3, 4) * 4, '=', STR_PAD_RIGHT);
        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            throw new InvalidFilterTreeException;
        }

        try {
            $tree = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidFilterTreeException;
        }

        if (!is_array($tree)) {
            throw new InvalidFilterTreeException;
        }

        /** @var array<string, mixed> $tree */
        return $tree;
    }

    /**
     * @param  array<string, mixed>  $tree
     * @return list<array{objectTypeId: string, slug: string, records: array<int, array<string, mixed>>}>
     */
    private function filterSections(array $tree, ObjectType $objectType, Request $request, User $viewer): array
    {
        $filterScope = $this->selectionResolver->filterScope($objectType, $viewer);

        $query = CustomRecord::query()->ofType($objectType);

        $this->filterCompiler->applyTree(
            $query,
            $filterScope['fields'],
            $tree,
            FieldVisibilityResolver::forRequest(),
            $objectType,
            $filterScope['expressions'],
        );

        /** @var array<int, array<string, mixed>> $records */
        $records = RecordResource::collection($query->get())->resolve($request);

        return [[
            'objectTypeId' => (string) $objectType->getKey(),
            'slug' => $objectType->slug,
            'records' => $records,
        ]];
    }
}
