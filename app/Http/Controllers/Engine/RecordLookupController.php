<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Http\Controllers\Abstracts\Controller;
use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\RecordSearchPredicate;
use App\Support\Engine\RecordTitleResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordLookupController extends Controller
{
    private int $optionLimit = 25;

    public function __construct(
        private readonly RecordSearchPredicate $searchPredicate,
        private readonly RecordTitleResolver $titleResolver,
    ) {}

    public function __invoke(Request $request, ObjectType $objectType): JsonResponse
    {
        $user = $this->actingUser($request);
        $offset = max(0, (int) $request->query('offset', '0'));

        $records = $this->matches($user, $objectType, (string) $request->query('q', ''), $offset);

        return new JsonResponse([
            'data' => $this->options($user, $records->take($this->optionLimit)),
            'meta' => ['hasMore' => $records->count() > $this->optionLimit],
        ]);
    }

    /**
     * @return Collection<int, CustomRecord>
     */
    private function matches(User $user, ObjectType $objectType, string $term, int $offset): Collection
    {
        $query = CustomRecord::query()
            ->ofType($objectType)
            ->whereNull('merged_into_record_id');

        $this->searchPredicate->applyIdentity($query, $user, (string) $objectType->getKey(), $term);

        return $query
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->offset($offset)
            ->limit($this->optionLimit + 1)
            ->get();
    }

    /**
     * @param  Collection<int, CustomRecord>  $records
     * @return list<array{value: string, label: string, description: string|null}>
     */
    private function options(User $user, Collection $records): array
    {
        return array_values(
            $records
                ->map(fn (CustomRecord $record): array => [
                    'value' => (string) $record->getKey(),
                    'label' => $this->titleResolver->titleFor($user, $record),
                    'description' => $record->record_number,
                ])
                ->all(),
        );
    }
}
