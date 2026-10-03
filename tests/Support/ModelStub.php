<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;

class ModelStub
{
    private static string $crockford = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, Model|EloquentCollection<int, Model>|null>  $relations
     * @return TModel
     */
    public static function make(string $model, array $attributes = [], array $relations = []): Model
    {
        $instance = new $model;

        $instance->setRawAttributes(self::rawAttributes($instance, [
            'id' => self::ulid($model),
            ...$attributes,
        ]), true);

        $instance->exists = true;

        foreach ($relations as $name => $related) {
            $instance->setRelation($name, $related);
        }

        return $instance;
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  list<array<string, mixed>>  $rows
     * @return EloquentCollection<int, TModel>
     */
    public static function collection(string $model, array $rows): EloquentCollection
    {
        return new EloquentCollection(array_map(
            static fn (array $attributes): Model => self::make($model, $attributes),
            $rows,
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function rawAttributes(Model $instance, array $attributes): array
    {
        $jsonCasts = ['array', 'json', 'object', 'collection', 'encrypted:array', 'encrypted:json', 'encrypted:object', 'encrypted:collection'];

        foreach ($attributes as $key => $value) {
            if ((is_array($value) || $value instanceof JsonSerializable) && $instance->hasCast($key, $jsonCasts)) {
                $attributes[$key] = json_encode($value);
            }
        }

        return $attributes;
    }

    public static function ulid(string $seed): string
    {
        $digest = hash('sha256', $seed);

        $ulid = '';

        for ($position = 0; $position < 26; $position++) {
            $byte = (int) hexdec(substr($digest, $position * 2, 2));

            $ulid .= self::$crockford[$position === 0 ? $byte % 8 : $byte % 32];
        }

        return $ulid;
    }
}
