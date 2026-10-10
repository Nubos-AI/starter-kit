<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Enums\Api\ApiErrorCode;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Engine\IndexRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiQueryCompiler
{
    private int $maxIncludePaths = 5;

    private string $rejectionDetail = 'i18n.backend.support.api.api_query_compiler.the_requested_query_parameter_is_not_supported_for_this';

    public function __construct(
        private FilterableFieldWhitelist $whitelist,
        private IndexRegistry $indexRegistry,
    ) {}

    public function reject(Request $request, ObjectType $type, ?User $user): ?JsonResponse
    {
        $parameter = $this->invalidParameter($request, $type, $user);

        if ($parameter === null) {
            return null;
        }

        return JsonApiErrorBag::single(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ApiErrorCode::ValidationFailed,
            __($this->rejectionDetail),
            ['parameter' => $parameter],
        );
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public function applyToQuery(Builder $query, Request $request, ObjectType $type, ?User $user): Builder
    {
        $query->with($this->eagerLoads($request));

        if ($this->invalidParameter($request, $type, $user) !== null) {
            return $query->whereRaw('1 = 0')->orderBy('id');
        }

        foreach ($this->filters($request) as $key => $value) {
            $query->where($this->column($key), $value);
        }

        $sort = $this->sort($request);

        if ($sort !== null) {
            $this->applySort($query, $sort['key'], $sort['direction'], $type);
        }

        return $query->orderBy('id');
    }

    private function invalidParameter(Request $request, ObjectType $type, ?User $user): ?string
    {
        $filters = $request->query('filter');

        if ($filters !== null && !is_array($filters)) {
            return 'filter';
        }

        if (is_array($filters)) {
            $filterable = $this->whitelist->filterableKeys($user, $type->getKey());

            foreach ($filters as $key => $value) {
                if (!is_string($key) || !is_scalar($value) || !in_array($key, $filterable, true)) {
                    return 'filter['.(is_string($key) ? $key : '').']';
                }
            }
        }

        $sort = $request->query('sort');

        if ($sort !== null) {
            if (!is_string($sort)) {
                return 'sort';
            }

            $sortable = $this->whitelist->sortableKeys($user, $type->getKey());

            if (!in_array($this->sortKey($sort), $sortable, true)) {
                return 'sort';
            }
        }

        $fieldsParameter = $this->invalidFieldsParameter($request, $type, $user);

        if ($fieldsParameter !== null) {
            return $fieldsParameter;
        }

        $include = $request->query('include');

        if ($include === null) {
            return null;
        }

        if (!is_string($include)) {
            return 'include';
        }

        $paths = FilterableFieldWhitelist::parseList($include);

        if (count($paths) > $this->maxIncludePaths) {
            return 'include';
        }

        foreach ($paths as $path) {
            if (!in_array($path, $this->whitelist->includePaths(), true)) {
                return 'include';
            }
        }

        return null;
    }

    private function invalidFieldsParameter(Request $request, ObjectType $type, ?User $user): ?string
    {
        $fields = $request->query('fields');

        if ($fields === null) {
            return null;
        }

        if (!is_array($fields)) {
            return 'fields';
        }

        $addressable = $this->whitelist->attributeKeys($user, $type->getKey());

        foreach ($fields as $resourceType => $value) {
            if (!is_string($resourceType) || $resourceType !== $type->slug) {
                return 'fields['.(is_string($resourceType) ? $resourceType : '').']';
            }

            if (!is_string($value)) {
                return 'fields['.$resourceType.']';
            }

            foreach (FilterableFieldWhitelist::parseList($value) as $key) {
                if (!in_array($key, $addressable, true)) {
                    return 'fields['.$resourceType.']';
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, scalar>
     */
    private function filters(Request $request): array
    {
        $filters = $request->query('filter');

        if (!is_array($filters)) {
            return [];
        }

        $valid = [];

        foreach ($filters as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $valid[$key] = $value;
            }
        }

        return $valid;
    }

    /**
     * @return array{key: string, direction: 'asc'|'desc'}|null
     */
    private function sort(Request $request): ?array
    {
        $sort = $request->query('sort');

        if (!is_string($sort)) {
            return null;
        }

        $key = $this->sortKey($sort);

        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'direction' => str_starts_with($sort, '-') ? 'desc' : 'asc',
        ];
    }

    private function sortKey(string $sort): string
    {
        return str_starts_with($sort, '-') ? substr($sort, 1) : $sort;
    }

    private function column(string $key): string
    {
        return $this->whitelist->spineColumn($key) ?? 'data->'.$key;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function applySort(Builder $query, string $key, string $direction, ObjectType $type): void
    {
        $column = $this->whitelist->spineColumn($key);

        if ($column !== null) {
            $query->orderBy($column, $direction);

            return;
        }

        $field = FieldDefinition::query()
            ->where('object_type_id', $type->getKey())
            ->where('key', $key)
            ->first();

        if (!$field instanceof FieldDefinition) {
            return;
        }

        $query->select($query->getModel()->getTable().'.*')
            ->addSelect(new Expression($this->indexRegistry->sortExpression($field).' as api_sort_value'))
            ->orderBy('api_sort_value', $direction);
    }

    /**
     * @return list<string>
     */
    private function eagerLoads(Request $request): array
    {
        $include = $request->query('include');

        $requested = is_string($include)
            ? array_intersect(FilterableFieldWhitelist::parseList($include), $this->whitelist->includePaths())
            : [];

        return array_values(array_unique(array_merge(['objectType'], $requested)));
    }
}
