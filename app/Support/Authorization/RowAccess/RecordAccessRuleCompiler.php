<?php

declare(strict_types=1);

namespace App\Support\Authorization\RowAccess;

use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\RecordFilterCompiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class RecordAccessRuleCompiler
{
    /**
     * @var array<string, Collection<int, FieldDefinition>>
     */
    private array $fieldMemo = [];

    public function __construct(
        private readonly RecordFilterCompiler $filterCompiler,
        private readonly FilterFieldKeyCollector $keyCollector,
        private readonly AccessRuleFieldSource $fieldSource,
    ) {}

    /**
     * @param  Builder<covariant CustomRecord>  $query
     */
    public function apply(Builder $query, EffectiveRecordAccessRules $rules, string $table): void
    {
        if ($rules->isUnrestricted()) {
            return;
        }

        $query->where(function (Builder $outer) use ($rules, $table): void {
            $outer->whereNotIn("{$table}.object_type_id", $rules->objectTypeIds());

            foreach ($rules->all() as $objectTypeId => $alternatives) {
                $outer->orWhere(function (Builder $branch) use ($objectTypeId, $alternatives, $table): void {
                    $branch->where("{$table}.object_type_id", $objectTypeId)
                        ->where(function (Builder $any) use ($objectTypeId, $alternatives): void {
                            foreach ($alternatives as $index => $chain) {
                                $method = $index === 0 ? 'where' : 'orWhere';

                                $any->{$method}(function (Builder $all) use ($objectTypeId, $chain): void {
                                    foreach ($chain as $tree) {
                                        $this->applyTree($all, $objectTypeId, $tree);
                                    }
                                });
                            }
                        });
                });
            }
        });
    }

    public function forget(): void
    {
        $this->fieldMemo = [];
    }

    /**
     * @param  Builder<covariant CustomRecord>  $query
     * @param  array<string, mixed>  $tree
     */
    private function applyTree(Builder $query, string $objectTypeId, array $tree): void
    {
        $fields = $this->fieldsFor($objectTypeId);

        foreach ($this->keyCollector->collect($tree) as $key) {
            if (!$fields->contains('key', $key)) {
                $query->whereRaw('1 = 0');

                return;
            }
        }

        $this->filterCompiler->applyValidatedTree($query, $fields, $tree);
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function fieldsFor(string $objectTypeId): Collection
    {
        return $this->fieldMemo[$objectTypeId] ??= $this->fieldSource->filterableFieldsOf($objectTypeId);
    }
}
