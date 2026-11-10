<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Attributes\Collects;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\CursorPaginator;

#[Collects(JsonApiRecordResource::class)]
class JsonApiRecordCollection extends ResourceCollection
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $paginator = $this->resource;

        if (!$paginator instanceof CursorPaginator) {
            return [
                'data' => $this->collection,
                'links' => [],
                'meta' => [],
            ];
        }

        $size = $paginator->perPage();

        $next = $paginator->nextCursor()?->encode();
        $previous = $paginator->previousCursor()?->encode();

        return [
            'data' => $this->collection,
            'links' => [
                'self' => $request->fullUrl(),
                'next' => $this->cursorLink($request, $next, $size),
                'prev' => $this->cursorLink($request, $previous, $size),
            ],
            'meta' => [
                'page' => [
                    'size' => $size,
                    'hasMore' => $paginator->hasMorePages(),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [];
    }

    private function cursorLink(Request $request, ?string $cursor, int $size): ?string
    {
        if ($cursor === null) {
            return null;
        }

        $parameters = $request->query();

        unset($parameters['page']);

        $parameters['page'] = [
            'cursor' => $cursor,
            'size' => $size,
        ];

        return $request->url().'?'.http_build_query($parameters);
    }
}
