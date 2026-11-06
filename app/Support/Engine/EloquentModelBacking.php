<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\DTOs\Engine\BackingRelationDescriptor;
use App\DTOs\Engine\FieldDescriptor;
use App\Enums\Authorization\CrudAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Enums\CustomFields\FieldType;
use App\Enums\Engine\ObjectTypeCapability;
use App\Models\ObjectType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

class EloquentModelBacking implements ObjectTypeBackingInterface
{
    /** @var array<string, FieldType> */
    private array $columnTypes = [
        'bool' => FieldType::Boolean,
        'boolean' => FieldType::Boolean,
        'smallint' => FieldType::Number,
        'int2' => FieldType::Number,
        'integer' => FieldType::Number,
        'int' => FieldType::Number,
        'int4' => FieldType::Number,
        'bigint' => FieldType::Number,
        'int8' => FieldType::Number,
        'numeric' => FieldType::Decimal,
        'decimal' => FieldType::Decimal,
        'float' => FieldType::Decimal,
        'float4' => FieldType::Decimal,
        'float8' => FieldType::Decimal,
        'double' => FieldType::Decimal,
        'date' => FieldType::Date,
        'timestamp' => FieldType::DateTime,
        'timestamptz' => FieldType::DateTime,
        'datetime' => FieldType::DateTime,
        'text' => FieldType::TextLong,
        'json' => FieldType::TextLong,
        'jsonb' => FieldType::TextLong,
    ];

    /** @var list<ObjectTypeCapability> */
    private array $supported = [ObjectTypeCapability::Relations];

    /** @var list<class-string<Relation<Model, Model, mixed>>> */
    private array $toOneRelations = [
        BelongsTo::class,
        HasOne::class,
        HasOneThrough::class,
        MorphOne::class,
        MorphTo::class,
    ];

    private readonly Model $model;

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function __construct(private readonly string $modelClass)
    {
        $this->model = new $modelClass;
    }

    /**
     * @return class-string<Model>
     */
    public function modelClass(ObjectType $type): string
    {
        return $this->modelClass;
    }

    /**
     * @return Builder<covariant Model>
     */
    public function newQuery(ObjectType $type): Builder
    {
        return $this->model->newQuery();
    }

    public function keyName(): string
    {
        return $this->model->getKeyName();
    }

    public function hasRows(ObjectType $type): bool
    {
        return $this->newQuery($type)->withoutGlobalScopes()->exists();
    }

    public function find(ObjectType $type, string $identifier): ?Model
    {
        return $this->newQuery($type)->whereKey($identifier)->first();
    }

    public function titleFor(Model $record): string
    {
        $column = $this->backingOption('title');

        if (!is_string($column)) {
            return (string) $record->getKey();
        }

        $title = $record->getAttribute($column);

        return is_scalar($title) ? (string) $title : (string) $record->getKey();
    }

    public function titleColumn(ObjectType $type): ?string
    {
        $column = $this->backingOption('title');

        return is_string($column) ? $column : null;
    }

    /**
     * @return list<FieldDescriptor>
     */
    public function fields(ObjectType $type): array
    {
        $hidden = $this->hiddenColumns();
        $casts = $this->model->getCasts();
        $fields = [];

        foreach (Schema::getColumns($this->model->getTable()) as $column) {
            $name = (string) $column['name'];

            if (in_array($name, $hidden, true) || $name === $this->keyName()) {
                continue;
            }

            $fields[] = new FieldDescriptor(
                key: $name,
                label: Str::headline($name),
                type: $this->fieldTypeFor($name, (string) $column['type_name'], $casts),
                isRequired: !(bool) $column['nullable'],
                isSortable: true,
                isFilterable: true,
                isTranslatable: false,
                column: $name,
            );
        }

        return $fields;
    }

    /**
     * @return array<string, BackingRelationDescriptor>
     */
    public function relations(ObjectType $type): array
    {
        $relations = [];

        foreach ($this->relationMethods() as $method) {
            /** @var Relation<Model, Model, mixed> $relation */
            $relation = $this->model->{$method->getName()}();

            $relations[$method->getName()] = new BackingRelationDescriptor(
                name: $method->getName(),
                isMany: !$this->isToOne($relation),
                counterpartObjectTypeId: null,
                relatedModelClass: $relation->getRelated()::class,
            );
        }

        return $relations;
    }

    public function supports(ObjectTypeCapability $capability): bool
    {
        return in_array($capability, $this->supported, true);
    }

    public function permissionFor(ObjectType $type, CrudAction|ObjectTypeAbility $ability): ?string
    {
        $permissions = $this->backingOption('permissions');

        if (!is_array($permissions)) {
            return "{$type->slug}.{$ability->value}";
        }

        $permission = $permissions[$ability->value] ?? null;

        return is_string($permission) ? $permission : null;
    }

    public function indexPath(ObjectType $type): string
    {
        $path = $this->backingOption('index_path');

        return is_string($path) ? $path : "/records/{$type->slug}";
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    private function isToOne(Relation $relation): bool
    {
        foreach ($this->toOneRelations as $class) {
            if ($relation instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<ReflectionMethod>
     */
    private function relationMethods(): array
    {
        $methods = [];

        foreach ((new ReflectionClass($this->model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            if ($method->isStatic()
                || $method->getNumberOfRequiredParameters() > 0
                || !$returnType instanceof ReflectionNamedType
                || !is_subclass_of($returnType->getName(), Relation::class)) {
                continue;
            }

            $methods[] = $method;
        }

        return $methods;
    }

    /**
     * @return list<string>
     */
    private function hiddenColumns(): array
    {
        $except = $this->backingOption('except');

        return array_values(array_unique([
            ...$this->model->getHidden(),
            ...(is_array($except) ? array_map(strval(...), $except) : []),
        ]));
    }

    /**
     * @param  array<string, mixed>  $casts
     */
    private function fieldTypeFor(string $column, string $type, array $casts): FieldType
    {
        $cast = $casts[$column] ?? null;

        if ($cast === 'boolean' || $cast === 'bool') {
            return FieldType::Boolean;
        }

        if ($cast === 'array' || $cast === 'json' || $cast === 'collection') {
            return FieldType::TextLong;
        }

        return $this->columnTypes[$type] ?? FieldType::TextShort;
    }

    private function backingOption(string $key): mixed
    {
        if (!method_exists($this->modelClass, 'objectTypeBacking')) {
            return null;
        }

        /** @var array<string, mixed> $options */
        $options = $this->modelClass::objectTypeBacking();

        return $options[$key] ?? null;
    }
}
