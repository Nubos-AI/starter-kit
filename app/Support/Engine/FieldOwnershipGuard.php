<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FieldOwnershipGuard
{
    public function assertBelongsTo(FieldDefinition $field, ObjectType $objectType): void
    {
        if ($field->object_type_id !== $objectType->getKey()) {
            throw new NotFoundHttpException(__('i18n.backend.support.engine.field_ownership_guard.this_field_does_not_belong_to_this_object_type'));
        }
    }
}
