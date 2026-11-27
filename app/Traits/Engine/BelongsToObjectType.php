<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Exceptions\Engine\AmbiguousRecordTypeException;
use App\Exceptions\Engine\UnknownRecordFieldException;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\IndexRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SortDirection;

trait BelongsToObjectType
{
    private ?string $objectTypeHint = null;

    /**
     * @param  Builder<CustomRecord>  $query
     */
    #[Scope]
    protected function ofType(Builder $query, ObjectType|string $type): void
    {
        $objectTypeId = match (true) {
            $type instanceof ObjectType => (string) $type->getKey(),
            Str::isUlid($type) => $type,
            default => (string) $this->resolveObjectType($type)->getKey(),
        };

        $model = $query->getModel();
        $model->withObjectTypeHint($objectTypeId);

        $query->where($model->getTable().'.object_type_id', $objectTypeId);
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    #[Scope]
    protected function whereField(Builder $query, string $key, mixed $operator, mixed $value = null): void
    {
        if (func_num_args() === 3) {
            $value = $operator;
            $operator = '=';
        }

        $query->where(
            DB::raw($this->fieldExpression($query, $key)),
            is_string($operator) ? $operator : '=',
            $value,
        );
    }

    /**
     * @param  Builder<CustomRecord>  $query
     */
    #[Scope]
    protected function orderByField(
        Builder $query,
        string $key,
        SortDirection $direction = SortDirection::Ascending,
    ): void {
        $query->orderBy(DB::raw($this->fieldExpression($query, $key)), $direction);
    }

    public function withObjectTypeHint(?string $objectTypeId): static
    {
        $this->objectTypeHint = $objectTypeId;

        return $this;
    }

    public function relationObjectTypeId(): ?string
    {
        $own = $this->attributes['object_type_id'] ?? null;

        return is_string($own) && $own !== '' ? $own : $this->objectTypeHint;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function newInstance($attributes = [], $exists = false): static
    {
        return parent::newInstance($attributes, $exists)
            ->withObjectTypeHint($this->relationObjectTypeId());
    }

    /**
     * @param  Builder<CustomRecord>  $query
     * @return literal-string
     */
    private function fieldExpression(Builder $query, string $key): string
    {
        $objectTypeId = $query->getModel()->relationObjectTypeId();

        if ($objectTypeId === null) {
            throw new AmbiguousRecordTypeException($key);
        }

        $field = app(ObjectTypeRegistry::class)->field($objectTypeId, $key);

        if (!$field instanceof FieldDefinition) {
            throw new UnknownRecordFieldException($key, $objectTypeId);
        }

        return app(IndexRegistry::class)->sortExpression($field);
    }

    private function resolveObjectType(string $identifier): ObjectType
    {
        $registry = app(ObjectTypeRegistry::class);

        return $registry->find($identifier)
            ?? $this->resolveBySlugOrKey($registry, $identifier);
    }

    private function resolveBySlugOrKey(ObjectTypeRegistry $registry, string $identifier): ObjectType
    {
        try {
            return $registry->bySlug($identifier);
        } catch (ModelNotFoundException) {
            return $registry->byKey($identifier);
        }
    }
}
