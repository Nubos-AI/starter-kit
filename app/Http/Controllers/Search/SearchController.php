<?php

declare(strict_types=1);

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Support\Search\SearchableObjectTypes;
use App\Support\Search\SearchVisibilityFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Meilisearch\Client;
use Meilisearch\Contracts\SearchQuery;

class SearchController extends Controller
{
    /**
     * @var int<0, max>
     */
    private int $groupLimit = 8;

    public function __construct(private readonly Client $meilisearch) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->actingUser($request);

        $validated = $request->validate([
            'q' => ['nullable', 'string'],
        ]);

        $term = trim((string) ($validated['q'] ?? ''));

        if ($term === '') {
            return $this->emptyResponse();
        }

        $indexUid = (new CustomRecord)->searchableAs();

        $queries = [];
        $queryTypes = [];

        foreach (SearchableObjectTypes::for($user) as $objectTypeId => $entry) {
            $queries[] = (new SearchQuery)
                ->setIndexUid($indexUid)
                ->setQuery($term)
                ->setFilter([SearchVisibilityFilter::forObjectTypes($user, [$objectTypeId])])
                ->setLimit($this->groupLimit)
                ->setAttributesToSearchOn($entry['attributes'])
                ->setAttributesToRetrieve(['id']);

            $queryTypes[] = $entry['objectType'];
        }

        if ($queries === []) {
            return $this->emptyResponse();
        }

        return new JsonResponse(['data' => ['groups' => $this->groups(
            $request,
            $queryTypes,
            $this->meilisearch->multiSearch($queries, null)['results'] ?? [],
        )]]);
    }

    private function emptyResponse(): JsonResponse
    {
        return new JsonResponse(['data' => ['groups' => []]]);
    }

    /**
     * @param  list<ObjectType>  $queryTypes
     * @param  list<array<string, mixed>>  $results
     * @return list<array{objectTypeId: string, type: string, label: string, records: array<int, array<string, mixed>>}>
     */
    private function groups(Request $request, array $queryTypes, array $results): array
    {
        $typesById = [];
        $idsByType = [];
        $allIds = [];

        foreach ($results as $index => $result) {
            $objectType = $queryTypes[$index] ?? null;

            if (!$objectType instanceof ObjectType) {
                continue;
            }

            $ids = array_map(static fn (array $hit): string => $hit['id'], $result['hits'] ?? []);
            $typesById[$objectType->getKey()] = $objectType;
            $idsByType[$objectType->getKey()] = $ids;
            $allIds = [...$allIds, ...$ids];
        }

        $records = $allIds === []
            ? collect()
            : CustomRecord::query()->whereIn('id', $allIds)->get()->keyBy('id');

        $groups = [];

        foreach ($idsByType as $objectTypeId => $ids) {
            $objectType = $typesById[$objectTypeId];
            $bucket = [];

            foreach ($ids as $id) {
                $record = $records->get($id);

                if ($record instanceof CustomRecord) {
                    $bucket[] = $record;
                }
            }

            if ($bucket === []) {
                continue;
            }

            $groups[] = [
                'objectTypeId' => $objectTypeId,
                'type' => $objectType->slug,
                'label' => $objectType->name,
                'records' => RecordResource::collection($bucket)->resolve($request),
            ];
        }

        return $groups;
    }
}
