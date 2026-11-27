<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\ObjectType;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class ObjectTypeRecordPresence
{
    public static string $blockedByRecordsReason = 'i18n.backend.support.engine.object_type_record_presence.the_object_type_still_holds_records';

    public function holdsRecords(ObjectType $objectType): bool
    {
        return isset($this->holdingRecords(collect([$objectType]))[(string) $objectType->getKey()]);
    }

    /**
     * @param  Collection<int, ObjectType>  $objectTypes
     * @return array<string, true>
     */
    public function holdingRecords(Collection $objectTypes): array
    {
        [$generic, $backed] = $objectTypes->partition(static fn (ObjectType $objectType): bool => $objectType->isGeneric());

        $holding = $generic->isEmpty() ? [] : ObjectType::query()
            ->withoutGlobalScopes()
            ->whereKey($generic->map(static fn (ObjectType $objectType): string => (string) $objectType->getKey())->all())
            ->whereExists(static fn (QueryBuilder $records): QueryBuilder => $records
                ->from('custom_records')
                ->whereColumn('custom_records.object_type_id', 'object_types.id'))
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        foreach ($backed as $objectType) {
            if ($objectType->hasRecords()) {
                $holding[] = (string) $objectType->getKey();
            }
        }

        return array_fill_keys($holding, true);
    }
}
