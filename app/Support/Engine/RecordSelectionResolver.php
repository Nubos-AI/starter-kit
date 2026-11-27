<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\DTOs\Engine\RecordQueryScopeData;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Aging\AgingEvaluator;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Reports\ReportDrillDownInput;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class RecordSelectionResolver
{
    public function __construct(
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly AgingEvaluator $agingEvaluator,
        private readonly SystemFilterFields $systemFields,
        private readonly RecordSearchPredicate $searchPredicate,
        private readonly RecordQueryScope $queryScope,
        private readonly ReportDrillDownInput $drillDownInput,
    ) {}

    /**
     * @return array<string, array<mixed>>
     */
    public function selectionRules(bool $withSortModel = false): array
    {
        $rules = [
            'selection' => ['required', 'array'],
            'selection.mode' => ['required', 'string', 'in:visible,all-matching'],
            'selection.filterModel' => ['array'],
            'selection.search' => ['nullable', 'string', 'max:255'],
            'selection.segmentId' => ['nullable', 'string'],
            'selection.drillDown' => ['nullable', 'array'],
            ...$this->drillDownInput->rules('selection.drillDown.'),
            'selection.includedIds' => ['array'],
            'selection.includedIds.*' => ['string'],
            'selection.excludedIds' => ['array'],
            'selection.excludedIds.*' => ['string'],
        ];

        return $withSortModel ? array_merge($rules, ['selection.sortModel' => ['array']]) : $rules;
    }

    /**
     * @return array{fields: Collection<int, FieldDefinition>, readable: Collection<int, FieldDefinition>, expressions: array<string, array{sql: literal-string, bindings: list<mixed>}>}
     */
    public function filterScope(ObjectType $objectType, User $viewer): array
    {
        return $this->scopeExcluding(
            $objectType,
            FieldVisibilityResolver::forRequest()->forbiddenReadFieldKeys($viewer, (string) $objectType->getKey()),
        );
    }

    /**
     * @return array{fields: Collection<int, FieldDefinition>, readable: Collection<int, FieldDefinition>, expressions: array<string, array{sql: literal-string, bindings: list<mixed>}>}
     */
    public function systemFilterScope(ObjectType $objectType): array
    {
        return $this->scopeExcluding($objectType, []);
    }

    /**
     * @param  array<string, mixed>  $selection
     * @param  Collection<int, FieldDefinition>  $filterableFields
     * @param  array<string, array{sql: literal-string, bindings: list<mixed>}>  $virtualFieldExpressions
     * @param  Collection<int, FieldDefinition>|null  $readableFields
     * @return Builder<CustomRecord>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function scopedQuery(
        ObjectType $objectType,
        array $selection,
        Collection $filterableFields,
        array $virtualFieldExpressions = [],
        ?Collection $readableFields = null,
        ?User $viewer = null,
    ): Builder {
        $query = CustomRecord::query()->ofType($objectType);

        if (($selection['mode'] ?? 'visible') !== 'all-matching') {
            $included = $this->ids($selection['includedIds'] ?? []);
            $query->whereIn('id', $included === [] ? ['__none__'] : $included);

            return $query;
        }

        $filterModel = is_array($selection['filterModel'] ?? null) ? $selection['filterModel'] : [];

        if ($this->filterCompiler->unresolvableKeys($filterableFields, $filterModel) !== []) {
            throw ValidationException::withMessages([
                'selection.filterModel' => __('i18n.backend.support.engine.record_selection_resolver.a_filter_of_this_selection_cannot_be_applied'),
            ]);
        }

        $segmentId = is_string($selection['segmentId'] ?? null) && $selection['segmentId'] !== ''
            ? $selection['segmentId']
            : null;

        $drillDown = $this->drillDownInput->parse(
            is_array($selection['drillDown'] ?? null) ? $selection['drillDown'] : [],
            $segmentId,
            'selection.drillDown.',
        );

        if ($segmentId !== null || $drillDown !== null) {
            $this->queryScope->narrowToContext(
                $query,
                new RecordQueryScopeData($objectType, $this->contextViewer($viewer), $segmentId, $drillDown),
                $virtualFieldExpressions,
            );
        }

        $this->filterCompiler->apply($query, $filterableFields, $filterModel, $virtualFieldExpressions);

        $this->searchPredicate->apply(
            $query,
            $readableFields ?? new Collection,
            is_string($selection['search'] ?? null) ? $selection['search'] : null,
        );

        $excluded = $this->ids($selection['excludedIds'] ?? []);

        if ($excluded !== []) {
            $query->whereNotIn('id', $excluded);
        }

        return $query;
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @return list<list<string>>
     */
    public function chunkedIds(Builder $query, int $chunkSize): array
    {
        $ids = $query->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        return array_chunk($ids, max(1, $chunkSize));
    }

    /**
     * @param  list<string>  $forbiddenFieldKeys
     * @return array{fields: Collection<int, FieldDefinition>, readable: Collection<int, FieldDefinition>, expressions: array<string, array{sql: literal-string, bindings: list<mixed>}>}
     */
    private function scopeExcluding(ObjectType $objectType, array $forbiddenFieldKeys): array
    {
        $objectType->loadMissing('fieldDefinitions');

        $readable = $objectType->fieldDefinitions
            ->filter(fn (FieldDefinition $field): bool => !$field->is_encrypted
                && !in_array($field->key, $forbiddenFieldKeys, true))
            ->values();

        return [
            'fields' => $readable
                ->filter(fn (FieldDefinition $field): bool => $field->is_filterable)
                ->values()
                ->toBase()
                ->concat($this->systemFields->agingFields((string) $objectType->getKey())),
            'readable' => $readable->toBase(),
            'expressions' => $this->agingEvaluator->expressions($objectType, $readable->toBase()),
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function contextViewer(?User $viewer): User
    {
        $viewer ??= Auth::user();

        if (!$viewer instanceof User) {
            throw new AuthorizationException(__('i18n.backend.support.engine.record_selection_resolver.the_selection_needs_a_viewer_to_apply_its_segment'));
        }

        return $viewer;
    }

    /**
     * @return list<string>
     */
    private function ids(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }
}
