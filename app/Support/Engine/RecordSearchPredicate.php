<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RecordSearchPredicate
{
    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly RecordTitleResolver $titleResolver,
        private readonly RecordSearchText $searchText,
        private readonly ObjectTypeRegistry $objectTypes,
    ) {}

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  Collection<int, FieldDefinition>  $readableFields
     */
    public function apply(Builder $query, Collection $readableFields, ?string $term): bool
    {
        $needle = trim((string) $term);

        if (mb_strlen($needle) < $this->minimumTermLength()) {
            return false;
        }

        $pattern = '%'.$this->escapeLike($needle).'%';

        /** @var list<literal-string> $arms */
        $arms = [];
        /** @var list<string> $bindings */
        $bindings = [];

        foreach ($this->searchableFields($readableFields) as $field) {
            $arms[] = $this->searchText->fieldText($field).' ILIKE ?';
            $bindings[] = $pattern;
        }

        foreach ($this->searchText->businessKeyColumns() as $column) {
            $arms[] = "{$column} ILIKE ?";
            $bindings[] = $pattern;
        }

        $this->constrain($query, $arms, $bindings, $pattern, true);

        return true;
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    public function applyIdentity(Builder $query, User $user, string $objectTypeId, ?string $term): bool
    {
        $needle = (string) $term;

        if (trim($needle) === '') {
            return false;
        }

        $pattern = '%'.$this->escapeLike($needle).'%';

        /** @var list<literal-string> $arms */
        $arms = [];
        /** @var list<string> $bindings */
        $bindings = [];

        $titleKey = $this->titleResolver->titleKey($user, $objectTypeId);
        $isTitleInSearchText = $titleKey === null || $this->isSearched($objectTypeId, $titleKey);

        if ($titleKey !== null) {
            $arms[] = "(data->>'".$this->indexRegistry->safeKey($titleKey)."') ILIKE ?";
            $bindings[] = $pattern;
        }

        foreach ($this->searchText->businessKeyColumns() as $column) {
            $arms[] = "{$column} ILIKE ?";
            $bindings[] = $pattern;
        }

        $this->constrain($query, $arms, $bindings, $pattern, $isTitleInSearchText);

        return true;
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @param  list<literal-string>  $arms
     * @param  list<string>  $bindings
     */
    private function constrain(Builder $query, array $arms, array $bindings, string $pattern, bool $isPrefiltered): void
    {
        $recheck = '('.implode(' OR ', $arms).')';

        if (!$isPrefiltered) {
            $query->whereRaw($recheck, $bindings);

            return;
        }

        $query->whereRaw("(search_text ILIKE ? AND {$recheck})", [$pattern, ...$bindings]);
    }

    private function isSearched(string $objectTypeId, string $fieldKey): bool
    {
        $field = $this->objectTypes->field($objectTypeId, $fieldKey);

        return $field instanceof FieldDefinition && $this->searchText->isSearchable($field);
    }

    /**
     * @param  Collection<int, FieldDefinition>  $readableFields
     * @return Collection<int, FieldDefinition>
     */
    private function searchableFields(Collection $readableFields): Collection
    {
        return $this->searchText
            ->searchableFields($readableFields)
            ->take($this->maxFields())
            ->values();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function minimumTermLength(): int
    {
        return (int) config('engine.search.minimum_term_length');
    }

    private function maxFields(): int
    {
        return (int) config('engine.search.max_fields');
    }
}
