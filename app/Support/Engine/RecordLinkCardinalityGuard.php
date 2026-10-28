<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\Engine\RelationCardinality;
use App\Models\RelationshipType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class RecordLinkCardinalityGuard
{
    public function __construct(private readonly RecordEndpointResolver $endpoints) {}

    /**
     * @throws ValidationException
     */
    public function assert(RelationshipType $type, bool $isDuplicate, ?string $existingParentId): void
    {
        if ($isDuplicate) {
            throw ValidationException::withMessages([
                'to_record_id' => __('i18n.backend.actions.engine.link_records_action.this_relationship_already_exists'),
            ]);
        }

        if ($type->cardinality !== RelationCardinality::OneToMany || $existingParentId === null) {
            return;
        }

        $isParentVisible = $this->endpoints->find($type->from_object_type_id, $existingParentId) instanceof Model;

        throw ValidationException::withMessages([
            'to_record_id' => $isParentVisible
                ? __('i18n.backend.actions.engine.link_records_action.the_target_record_is_already_linked_in_this_one')
                : __('i18n.backend.actions.engine.link_records_action.the_relationship_violates_the_configured_cardinality_or_already_exists'),
        ]);
    }
}
