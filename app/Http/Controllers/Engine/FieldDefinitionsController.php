<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\CreateFieldDefinitionAction;
use App\Actions\Engine\DeleteFieldDefinitionAction;
use App\Actions\Engine\UpdateFieldDefinitionAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\FieldOwnershipGuard;
use App\Support\I18n\TranslatableInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FieldDefinitionsController extends Controller
{
    /** @var list<string> */
    /** @var list<string> */
    private array $translatableAttributes = ['label', 'description'];

    public function __construct(
        private readonly CreateFieldDefinitionAction $createFieldDefinition,
        private readonly UpdateFieldDefinitionAction $updateFieldDefinition,
        private readonly DeleteFieldDefinitionAction $deleteFieldDefinition,
        private readonly FieldOwnershipGuard $ownershipGuard,
        private readonly TranslatableInput $translatableInput,
    ) {}

    public function store(Request $request, ObjectType $objectType): RedirectResponse
    {
        $input = $this->translatableInput->forCreate($request, [
            ...$request->except($this->translatableAttributes),
            'object_type_id' => $objectType->getKey(),
        ], $this->translatableAttributes);

        $this->createFieldDefinition->execute($input);

        return $this->redirectToEdit($objectType);
    }

    public function update(Request $request, ObjectType $objectType, FieldDefinition $field): RedirectResponse
    {
        $this->guardBelongsToType($field, $objectType);

        $input = $this->translatableInput->forUpdate(
            $request,
            $request->except($this->translatableAttributes),
            $this->translatableAttributes,
        );

        $this->updateFieldDefinition->execute($field, $input);

        return $this->redirectToEdit($objectType);
    }

    public function destroy(ObjectType $objectType, FieldDefinition $field): RedirectResponse
    {
        $this->guardBelongsToType($field, $objectType);

        $this->deleteFieldDefinition->execute($field);

        return $this->redirectToEdit($objectType);
    }

    private function guardBelongsToType(FieldDefinition $field, ObjectType $objectType): void
    {
        $this->ownershipGuard->assertBelongsTo($field, $objectType);
    }

    private function redirectToEdit(ObjectType $objectType): RedirectResponse
    {
        return to_route('engine.object-types.edit.fields', ['objectType' => $objectType->slug]);
    }
}
