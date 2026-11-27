<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\ObjectTypeAudience;

class ConditionFieldPresenter
{
    public function __construct(
        private readonly SystemFilterFields $systemFilterFields,
        private readonly ObjectTypeAudience $objectTypeAudience,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function forObjectType(User $user, ObjectType $objectType): array
    {
        $objectTypeId = (string) $objectType->getKey();
        $tenantId = (string) $user->tenant_id;

        $systemFields = $this->systemFilterFields->presented(
            $objectTypeId,
            $this->objectTypeAudience->userOptions($objectType, $tenantId),
            array_values($this->objectTypeAudience->teamOptions($objectType, $tenantId)),
        );

        $fields = FieldDefinition::query()
            ->where('object_type_id', $objectTypeId)
            ->where('is_filterable', true)
            ->where('is_encrypted', false)
            ->orderBy('list_position')
            ->get()
            ->toBase()
            ->reject(fn (FieldDefinition $field): bool => $this->systemFilterFields->isSystemField($field));

        return array_merge(
            $this->systemFilterFields->payload($fields),
            $this->systemFilterFields->payload($systemFields),
        );
    }
}
