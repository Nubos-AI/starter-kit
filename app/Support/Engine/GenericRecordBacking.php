<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Contracts\Engine\ObjectTypeBackingInterface;
use App\DTOs\Engine\BackingRelationDescriptor;
use App\DTOs\Engine\FieldDescriptor;
use App\DTOs\Engine\RecordRelationDescriptor;
use App\Enums\Authorization\CrudAction;
use App\Enums\Authorization\ObjectTypeAbility;
use App\Enums\Engine\ObjectTypeCapability;
use App\Enums\Engine\RelationCardinality;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Support\I18n\TranslatableValueResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GenericRecordBacking implements ObjectTypeBackingInterface
{
    public function __construct(
        private readonly ObjectTypeRegistry $registry,
        private readonly TranslatableValueResolver $labels,
    ) {}

    /**
     * @return class-string<CustomRecord>
     */
    public function modelClass(ObjectType $type): string
    {
        return $this->registry->modelClassFor($type) ?? CustomRecord::class;
    }

    /**
     * @return Builder<CustomRecord>
     */
    public function newQuery(ObjectType $type): Builder
    {
        $modelClass = $this->modelClass($type);

        return $modelClass::query()->ofType($type);
    }

    public function keyName(): string
    {
        return (new CustomRecord)->getKeyName();
    }

    public function hasRows(ObjectType $type): bool
    {
        return CustomRecord::query()
            ->withoutGlobalScopes()
            ->ofType($type)
            ->whereNull('deleted_at')
            ->exists();
    }

    public function find(ObjectType $type, string $identifier): ?Model
    {
        return $this->newQuery($type)->whereKey($identifier)->first();
    }

    public function titleFor(Model $record): string
    {
        return $record instanceof CustomRecord ? $record->title() : (string) $record->getKey();
    }

    public function titleColumn(ObjectType $type): ?string
    {
        return null;
    }

    /**
     * @return list<FieldDescriptor>
     */
    public function fields(ObjectType $type): array
    {
        $fields = [];

        foreach ($this->registry->fields((string) $type->getKey()) as $field) {
            $fields[] = new FieldDescriptor(
                key: $field->key,
                label: $this->labelFor($field),
                type: $field->field_type,
                isRequired: $field->is_required,
                isSortable: $field->is_sortable,
                isFilterable: $field->is_filterable,
                isTranslatable: $field->is_translatable,
                column: null,
            );
        }

        return $fields;
    }

    /**
     * @return array<string, BackingRelationDescriptor>
     */
    public function relations(ObjectType $type): array
    {
        $relations = [];

        foreach ($this->registry->relationDescriptors((string) $type->getKey()) as $descriptor) {
            $relations[$descriptor->name] = new BackingRelationDescriptor(
                name: $descriptor->name,
                isMany: $this->isMany($descriptor),
                counterpartObjectTypeId: $descriptor->counterpartObjectTypeId,
                relatedModelClass: CustomRecord::class,
            );
        }

        return $relations;
    }

    public function supports(ObjectTypeCapability $capability): bool
    {
        return in_array($capability->value, config('engine.record_capabilities', []), true);
    }

    public function permissionFor(ObjectType $type, CrudAction|ObjectTypeAbility $ability): string
    {
        return "{$type->slug}.{$ability->value}";
    }

    public function indexPath(ObjectType $type): string
    {
        return "/records/{$type->slug}";
    }

    private function isMany(RecordRelationDescriptor $descriptor): bool
    {
        return $descriptor->cardinality !== RelationCardinality::OneToMany
            || $descriptor->direction->isOutgoing();
    }

    private function labelFor(FieldDefinition $field): string
    {
        $label = $this->labels->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }
}
