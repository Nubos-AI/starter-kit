<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class RecordEndpointResolver
{
    public function __construct(
        private readonly ObjectTypeRegistry $types,
        private readonly ObjectTypeBackingRegistry $backings,
    ) {}

    public function find(ObjectType|string $type, string $recordId, bool $withTrashed = false): ?Model
    {
        $query = $this->newQuery($type);

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $query->whereKey($recordId)->first();
    }

    /**
     * @param  list<string>  $recordIds
     * @return Collection<string, Model>
     */
    public function findMany(ObjectType|string $type, array $recordIds): Collection
    {
        if ($recordIds === []) {
            return new Collection;
        }

        return $this->newQuery($type)->whereKey($recordIds)->get()->keyBy(
            static fn (Model $model): string => (string) $model->getKey(),
        );
    }

    /**
     * @return Builder<covariant Model>
     */
    public function newQuery(ObjectType|string $type): Builder
    {
        $objectType = $type instanceof ObjectType ? $type : $this->types->byId($type);

        return $this->backings->for($objectType)->newQuery($objectType);
    }

    /**
     * @return class-string<Model>
     */
    public function modelClassFor(ObjectType|string $type): string
    {
        $objectType = $type instanceof ObjectType ? $type : $this->types->byId($type);

        return $this->backings->for($objectType)->modelClass($objectType);
    }

    public function titleOf(ObjectType|string $type, Model $record): string
    {
        $objectType = $type instanceof ObjectType ? $type : $this->types->byId($type);

        return $this->backings->for($objectType)->titleFor($record);
    }

    public function titleColumn(ObjectType|string $type): ?string
    {
        $objectType = $type instanceof ObjectType ? $type : $this->types->byId($type);

        return $this->backings->for($objectType)->titleColumn($objectType);
    }
}
