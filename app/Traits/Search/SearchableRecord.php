<?php

declare(strict_types=1);

namespace App\Traits\Search;

use App\Models\FieldDefinition;
use App\Support\CustomFields\FieldTypeRegistry;
use App\Support\Engine\ObjectTypeRegistry;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;

/**
 * @property array<string, mixed>|null $data
 * @property string $tenant_id
 * @property string|null $team_id
 * @property string|null $owner_id
 * @property string $object_type_id
 *
 * @method bool trashed()
 */
trait SearchableRecord
{
    use Searchable;

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $registry = app(FieldTypeRegistry::class);
        $data = is_array($this->data) ? $this->data : [];

        $searchable = [];

        foreach ($this->searchableFieldDefinitions() as $field) {
            $searchable[$field->key] = $registry->toSearchable($data[$field->key] ?? null, $field);
        }

        return [
            ...$searchable,
            'tenant_id' => $this->tenant_id,
            'team_id' => $this->team_id,
            'owner_id' => $this->owner_id,
            'object_type_id' => $this->object_type_id,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return !$this->trashed();
    }

    /**
     * @return Collection<int, FieldDefinition>
     */
    private function searchableFieldDefinitions(): Collection
    {
        return app(ObjectTypeRegistry::class)
            ->fields($this->object_type_id)
            ->filter(static fn (FieldDefinition $field): bool => $field->is_searchable && !$field->is_encrypted)
            ->values();
    }
}
