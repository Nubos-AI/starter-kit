<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FieldDefinition;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin FieldDefinition
 */
class FieldDefinitionResource extends JsonResource
{
    /**
     * @param  Collection<int, FieldDefinition>|array<int, FieldDefinition>  $resource
     * @return AnonymousResourceCollection
     */
    public static function collection($resource)
    {
        $items = $resource instanceof Collection ? $resource : collect($resource);
        $user = auth()->user();
        $first = $items->first();

        if ($user instanceof User && $first instanceof FieldDefinition) {
            $readable = FieldVisibilityResolver::forRequest()
                ->readableFieldKeys($user, $first->object_type_id);

            $items = $items
                ->filter(fn (FieldDefinition $field): bool => in_array($field->key, $readable, true))
                ->values();
        }

        return parent::collection($items);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FieldDefinition $field */
        $field = $this->resource;

        return [
            'key' => $field->key,
            'field_group_id' => $field->field_group_id,
            'field_type' => $field->field_type->value,
            'label' => $this->resolveLabel($field),
            'description' => $this->resolveDescription($field),
            'is_required' => $field->is_required,
            'is_sortable' => $field->is_sortable,
            'is_filterable' => $field->is_filterable,
            'is_default_column' => $field->is_default_column,
            ...$field->only(config('modules.fields.attributes', [])),
            'list_position' => $field->list_position,
            'config' => $field->config,
            'validation_rules' => $field->validation_rules,
            'default_value' => $field->default_value,
        ];
    }

    private function resolveLabel(FieldDefinition $field): string
    {
        $label = (new TranslatableValueResolver)->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }

    private function resolveDescription(FieldDefinition $field): ?string
    {
        $description = (new TranslatableValueResolver)->resolve($field->i18n_descriptions);

        return is_string($description) && $description !== '' ? $description : null;
    }
}
