<?php

declare(strict_types=1);

namespace App\Traits\Engine;

use App\Attributes\Engine\BackedByObjectType;
use App\Exceptions\Engine\MissingObjectTypeBackingException;
use App\Models\ObjectType;
use App\Support\Engine\ObjectTypeBackingRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use ReflectionClass;

trait RepresentsObjectType
{
    public function objectType(): ObjectType
    {
        $attributes = (new ReflectionClass($this))->getAttributes(BackedByObjectType::class);

        if ($attributes === []) {
            throw new MissingObjectTypeBackingException(static::class);
        }

        return app(ObjectTypeRegistry::class)->bySlug($attributes[0]->newInstance()->slug);
    }

    public function objectTypeTitle(): string
    {
        return app(ObjectTypeBackingRegistry::class)
            ->for($this->objectType())
            ->titleFor($this);
    }
}
