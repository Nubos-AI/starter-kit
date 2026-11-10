<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Http\Resources\RecordResource;
use App\Models\CustomRecord;
use App\Support\Api\FilterableFieldWhitelist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin CustomRecord
 */
class JsonApiRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomRecord $record */
        $record = $this->resource;

        /** @var array<string, mixed> $delegated */
        $delegated = (new RecordResource($record))->toArray($request);

        $slug = $record->objectType->slug;

        return [
            'type' => $slug,
            'id' => $record->id,
            'attributes' => $this->resolveAttributes($request, $delegated, $slug),
            'relationships' => $this->relationships($request, $record),
            'links' => [
                'self' => url('/api/v1/'.$slug.'/'.$record->id),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $delegated
     * @return array<string, mixed>
     */
    private function resolveAttributes(Request $request, array $delegated, string $slug): array
    {
        $requested = $this->requestedKeys($request, $slug);

        if ($requested === null) {
            return Arr::only($delegated, FilterableFieldWhitelist::$spineAttributes);
        }

        $attributes = Arr::only(
            $delegated,
            array_intersect(FilterableFieldWhitelist::$spineAttributes, $requested),
        );

        $data = $delegated['data'] ?? null;

        foreach ($requested as $key) {
            if (in_array($key, FilterableFieldWhitelist::$spineAttributes, true)) {
                continue;
            }

            $attributes[$key] = is_array($data) ? ($data[$key] ?? null) : null;
        }

        return $attributes;
    }

    /**
     * @return list<string>|null
     */
    private function requestedKeys(Request $request, string $slug): ?array
    {
        $fields = $request->query('fields');

        $requested = is_array($fields) ? ($fields[$slug] ?? null) : null;

        if (!is_string($requested)) {
            return null;
        }

        return FilterableFieldWhitelist::parseList($requested);
    }

    /**
     * @return array<string, mixed>
     */
    private function relationships(Request $request, CustomRecord $record): array
    {
        $available = [
            'objectType' => [
                'data' => [
                    'type' => 'objectTypes',
                    'id' => $record->object_type_id,
                ],
            ],
        ];

        foreach (config('modules.records.api_relationships', []) as $name => $relationship) {
            $id = $record->getAttribute($relationship['column']);
            $available[$name] = ['data' => $id === null ? null : ['type' => $relationship['type'], 'id' => $id]];
        }

        $include = $request->query('include');

        if (!is_string($include)) {
            return $available;
        }

        return Arr::only($available, FilterableFieldWhitelist::parseList($include));
    }
}
