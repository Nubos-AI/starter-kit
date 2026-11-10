<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use Illuminate\Auth\Access\AuthorizationException;

class SystemObjectTypeGuard
{
    public function assertFieldsAreManageable(ObjectType $objectType): void
    {
        if ($objectType->is_system) {
            throw new AuthorizationException(__('i18n.backend.support.engine.system_object_type_guard.fields_of_a_system_object_type_cannot_be_managed'));
        }
    }

    public function assertFieldIsManageable(FieldDefinition $field): void
    {
        $this->assertFieldsAreManageable($field->objectType);
    }
}
