<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\CascadeBehavior;
use App\Enums\Engine\ObjectTypeCapability;
use Illuminate\Validation\ValidationException;

class RelationshipEndpointGuard
{
    public function __construct(
        private readonly ObjectTypeRegistry $types,
        private readonly ObjectTypeCapabilityGuard $capabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    public function assertUsable(array $validated): void
    {
        foreach (['from_object_type_id', 'to_object_type_id'] as $field) {
            $this->assertRelationsSupported((string) $validated[$field], $field);
        }

        $this->assertCascadeStaysGeneric($validated);
    }

    /**
     * @throws ValidationException
     */
    private function assertRelationsSupported(string $objectTypeId, string $field): void
    {
        $objectType = $this->types->byId($objectTypeId);

        if (!$this->capabilities->supports($objectType, ObjectTypeCapability::Relations)) {
            throw ValidationException::withMessages([
                $field => __('i18n.backend.support.engine.relationship_endpoint_guard.the_object_type_does_not_support_relationships', ['value1' => $objectType->name]),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private function assertCascadeStaysGeneric(array $validated): void
    {
        if ($validated['cascade_behavior'] !== CascadeBehavior::Cascade->value) {
            return;
        }

        foreach (['from_object_type_id', 'to_object_type_id'] as $field) {
            if (!$this->types->byId((string) $validated[$field])->isGeneric()) {
                throw ValidationException::withMessages([
                    'cascade_behavior' => __('i18n.backend.support.engine.relationship_endpoint_guard.cascading_deletion_requires_a_generic_object_type_on_both'),
                ]);
            }
        }
    }
}
