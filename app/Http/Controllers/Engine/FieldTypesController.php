<?php

declare(strict_types=1);

namespace App\Http\Controllers\Engine;

use App\Actions\Engine\ChangeFieldTypeAction;
use App\Enums\CustomFields\FieldType;
use App\Http\Controllers\Abstracts\Controller;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\Engine\FieldOwnershipGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class FieldTypesController extends Controller
{
    public function __construct(
        private readonly ChangeFieldTypeAction $changeFieldType,
        private readonly FieldOwnershipGuard $ownershipGuard,
    ) {}

    /**
     * @throws Throwable
     */
    public function update(Request $request, ObjectType $objectType, FieldDefinition $field): RedirectResponse
    {
        $this->ownershipGuard->assertBelongsTo($field, $objectType);

        $validated = Validator::make($request->all(), [
            'field_type' => ['required', Rule::enum(FieldType::class)],
            'confirm_lossy' => ['sometimes', 'boolean'],
        ])->validate();

        $this->changeFieldType->execute(
            $field,
            FieldType::from((string) $validated['field_type']),
            (bool) ($validated['confirm_lossy'] ?? false),
        );

        return to_route('engine.object-types.edit.fields', ['objectType' => $objectType->slug]);
    }
}
