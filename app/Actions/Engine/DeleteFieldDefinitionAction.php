<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Enums\CustomFields\ReservedFieldKey;
use App\Models\FieldDefinition;
use App\Support\Engine\RecordSearchText;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeleteFieldDefinitionAction
{
    public function __construct(
        private readonly SystemObjectTypeGuard $systemObjectTypeGuard,
        private readonly RecordSearchText $searchText,
        private readonly RebuildRecordSearchTextAction $rebuildSearchText,
    ) {}

    /**
     * @throws ValidationException
     * @throws Throwable
     */
    public function execute(FieldDefinition $field): void
    {
        $this->systemObjectTypeGuard->assertFieldIsManageable($field);

        if (ReservedFieldKey::isReserved($field->key)) {
            throw ValidationException::withMessages([
                'key' => __('i18n.backend.actions.engine.delete_field_definition_action.the_field_belongs_to_every_object_type_and_cannot', ['value1' => $field->key]),
            ]);
        }

        DB::transaction(function () use ($field): void {
            $field->delete();

            if ($this->searchText->isSearchable($field)) {
                $this->rebuildSearchText->execute($field->objectType()->firstOrFail());
            }
        });
    }
}
