<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Abstracts\Controller;
use App\Http\Resources\Api\JsonApiRecordResource;
use App\Models\User;
use App\Support\Api\RecordSearchService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class SearchController extends Controller
{
    public function __construct(private RecordSearchService $records) {}

    public function __invoke(Request $request): ResourceCollection
    {
        /** @var array{q: string} $validated */
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $this->records->search($user, $validated['q']);

        return JsonApiRecordResource::collection($result->hits)
            ->additional(['meta' => ['search' => ['source' => $result->source->value]]]);
    }
}
