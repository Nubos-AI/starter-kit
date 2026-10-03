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
    /**
     * @var list<literal-string>
     */
    private array $businessKeyColumns = ['record_number', 'external_reference_id'];

    public function __construct(
        private readonly IndexRegistry $indexRegistry,
        private readonly RecordTitleResolver $titleResolver,
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
            $arms[] = "(data->>'".$this->indexRegistry->safeKey($field->key)."') ILIKE ?";
            $bindings[] = $pattern;
        }

        foreach ($this->businessKeyColumns as $column) {
            $arms[] = "{$column} ILIKE ?";
            $bindings[] = $pattern;
        }

        $query->whereRaw('('.implode(' OR ', $arms).')', $bindings);

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

        if ($titleKey !== null) {
            $arms[] = "(data->>'".$this->indexRegistry->safeKey($titleKey)."') ILIKE ?";
            $bindings[] = $pattern;
        }

        foreach ($this->businessKeyColumns as $column) {
            $arms[] = "{$column} ILIKE ?";
            $bindings[] = $pattern;
        }

        $query->whereRaw('('.implode(' OR ', $arms).')', $bindings);

        return true;
    }

    /**
     * @param  Collection<int, FieldDefinition>  $readableFields
     * @return Collection<int, FieldDefinition>
     */
    private function searchableFields(Collection $readableFields): Collection
    {
        return $readableFields
            ->filter(fn (FieldDefinition $field): bool => $this->isSearchable($field))
            ->take($this->maxFields())
            ->values();
    }

    private function isSearchable(FieldDefinition $field): bool
    {
        return $field->is_searchable
            && !$field->is_translatable
            && !$field->is_encrypted
            && $field->field_type->isFreeTextSearchable();
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
