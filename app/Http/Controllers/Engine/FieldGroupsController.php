<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\CreateFieldGroupAction;
use App\Actions\Engine\DeleteFieldGroupAction;
use App\Actions\Engine\UpdateFieldGroupAction;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\FieldGroup;
use App\Models\ObjectType;
use App\Support\I18n\TranslatableInput;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FieldGroupsController extends Controller
{
    /** @var list<string> */
    /** @var list<string> */
    private array $translatableAttributes = ['label', 'description'];

    public function __construct(
        private readonly CreateFieldGroupAction $createFieldGroup,
        private readonly UpdateFieldGroupAction $updateFieldGroup,
        private readonly DeleteFieldGroupAction $deleteFieldGroup,
        private readonly TranslatableInput $translatableInput,
    ) {}

    public function store(Request $request, ObjectType $objectType): RedirectResponse
    {
        $input = $this->translatableInput->forCreate(
            $request,
            $request->except($this->translatableAttributes),
            $this->translatableAttributes,
        );

        $this->createFieldGroup->execute($objectType, $input);

        return $this->redirectToFields($objectType);
    }

    public function update(Request $request, ObjectType $objectType, FieldGroup $fieldGroup): RedirectResponse
    {
        $this->guardBelongsToType($fieldGroup, $objectType);

        $input = $this->translatableInput->forUpdate(
            $request,
            $request->except($this->translatableAttributes),
            $this->translatableAttributes,
        );

        $this->updateFieldGroup->execute($fieldGroup, $input);

        return $this->redirectToFields($objectType);
    }

    public function destroy(ObjectType $objectType, FieldGroup $fieldGroup): RedirectResponse
    {
        $this->guardBelongsToType($fieldGroup, $objectType);

        $this->deleteFieldGroup->execute($fieldGroup);

        return $this->redirectToFields($objectType);
    }

    private function guardBelongsToType(FieldGroup $fieldGroup, ObjectType $objectType): void
    {
        if ($fieldGroup->object_type_id !== $objectType->getKey()) {
            throw new NotFoundHttpException(__('i18n.backend.http.controllers.engine.field_groups_controller.the_field_group_does_not_belong_to_this_object'));
        }
    }

    private function redirectToFields(ObjectType $objectType): RedirectResponse
    {
        return to_route('engine.object-types.edit.fields', ['objectType' => $objectType->slug]);
    }
}
