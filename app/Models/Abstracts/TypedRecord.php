<?php

declare(strict_types=1);

namespace App\Models\Abstracts;

use App\Models\CustomRecord;
use App\Models\ObjectType;
use App\Scopes\ObjectTypeScope;
use App\Support\Engine\ObjectTypeRegistry;
use Database\Factories\CustomRecordFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Throwable;

#[ScopedBy([ObjectTypeScope::class])]
abstract class TypedRecord extends CustomRecord
{
    abstract public static function objectTypeSlug(): string;

    public static function boundObjectType(): ObjectType
    {
        return app(ObjectTypeRegistry::class)->bySlug(static::objectTypeSlug());
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $attributes
     *
     * @throws Throwable
     */
    public static function createRecord(array $values, array $attributes = []): static
    {
        $created = static::boundObjectType()->createRecord($values, $attributes);

        return static::query()->whereKey($created->getKey())->firstOrFail();
    }

    public function relationObjectTypeId(): ?string
    {
        return parent::relationObjectTypeId() ?? (string) static::boundObjectType()->getKey();
    }

    protected static function booted(): void
    {
        parent::booted();

        $model = static::class;

        static::creating(function (CustomRecord $record) use ($model): void {
            $record->object_type_id = $model::boundObjectType()->getKey();
        });
    }

    protected static function newFactory(): CustomRecordFactory
    {
        $model = static::class;

        return CustomRecordFactory::new()
            ->forModel($model)
            ->state(fn (): array => ['object_type_id' => $model::boundObjectType()->getKey()]);
    }
}
