<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Models\FieldGroup;
use App\Models\ObjectType;
use App\Support\I18n\TranslatableValueResolver;

class FieldGroupPresenter
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function payload(ObjectType $objectType): array
    {
        $resolver = new TranslatableValueResolver;

        return FieldGroup::query()
            ->where('object_type_id', $objectType->getKey())
            ->orderBy('position')
            ->orderBy('key')
            ->get()
            ->map(function (FieldGroup $group) use ($resolver): array {
                $label = $resolver->resolve($group->i18n_labels);
                $description = $resolver->resolve($group->i18n_descriptions);

                return [
                    'id' => $group->id,
                    'key' => $group->key,
                    'label' => is_string($label) && $label !== '' ? $label : $group->key,
                    'description' => is_string($description) && $description !== '' ? $description : null,
                    'position' => $group->position,
                ];
            })
            ->all();
    }
}
