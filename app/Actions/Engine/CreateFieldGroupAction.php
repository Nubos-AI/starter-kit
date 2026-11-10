<?php

declare(strict_types=1);

namespace App\Actions\Engine;

use App\Models\FieldGroup;
use App\Models\ObjectType;
use App\Support\Engine\SystemObjectTypeGuard;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class CreateFieldGroupAction
{
    public function __construct(private readonly SystemObjectTypeGuard $systemObjectTypeGuard) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws Throwable
     */
    public function execute(ObjectType $objectType, array $input): FieldGroup
    {
        $this->systemObjectTypeGuard->assertFieldsAreManageable($objectType);

        $validated = Validator::make($input, [
            'key' => [
                'required',
                'string',
                'regex:/^[a-z][a-z0-9_]*$/',
                'max:255',
                Rule::unique('field_groups', 'key')
                    ->where('object_type_id', $objectType->getKey())
                    ->whereNull('deleted_at'),
            ],
            'i18n_labels' => ['nullable', 'array'],
            'i18n_descriptions' => ['nullable', 'array'],
            'position' => ['integer', 'min:0'],
        ])->validate();

        $group = new FieldGroup;
        $group->object_type_id = $objectType->getKey();
        $group->key = (string) $validated['key'];
        $group->i18n_labels = $validated['i18n_labels'] ?? null;
        $group->i18n_descriptions = $validated['i18n_descriptions'] ?? null;
        $group->position = (int) ($validated['position'] ?? $this->nextPosition($objectType));
        $group->save();

        return $group;
    }

    private function nextPosition(ObjectType $objectType): int
    {
        return (int) FieldGroup::query()
            ->where('object_type_id', $objectType->getKey())
            ->max('position') + 1;
    }
}
