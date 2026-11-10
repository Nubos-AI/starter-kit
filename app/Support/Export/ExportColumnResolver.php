<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Enums\CustomFields\FieldType;
use App\Enums\Export\ExportIdentityColumn;
use App\Models\CustomRecord;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\RecordLink;
use App\Models\RelationshipType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\ObjectTypeFieldLookup;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\RecordExchangeIdentity;
use App\Support\I18n\TranslatableValueResolver;
use App\Traits\Export\BuildsUniqueHeaders;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ExportColumnResolver
{
    use BuildsUniqueHeaders;

    private string $relationValueSeparator = ', ';

    public function __construct(
        private readonly TranslatableValueResolver $labels,
        private readonly RecordExchangeIdentity $exchangeIdentity,
        private readonly ObjectTypeRegistry $objectTypes,
        private readonly ObjectTypeFieldLookup $fieldLookup,
    ) {}

    /**
     * @param  array<int|string, mixed>  $selectedKeys
     * @return array{headers: list<string>, descriptors: list<array<string, mixed>>}
     */
    public function resolve(User $user, ObjectType $objectType, array $selectedKeys): array
    {
        $objectType->loadMissing('fieldDefinitions');

        $forbidden = FieldVisibilityResolver::forRequest()
            ->forbiddenReadFieldKeys($user, (string) $objectType->getKey());

        /** @var Collection<int, FieldDefinition> $exportable */
        $exportable = $objectType->fieldDefinitions
            ->filter(fn (FieldDefinition $field): bool => !$field->is_encrypted && !in_array($field->key, $forbidden, true))
            ->values();

        $selected = array_values(array_filter(
            $selectedKeys,
            static fn (mixed $key): bool => is_string($key) && $key !== '',
        ));

        $orderedKeys = $selected === []
            ? $this->defaultKeyOrder($exportable)
            : $selected;

        $fieldsByKey = $exportable->keyBy('key');

        /** @var list<array<string, mixed>> $descriptors */
        $descriptors = [];

        /** @var array<string, true> $takenHeaders */
        $takenHeaders = [];

        foreach ($orderedKeys as $key) {
            $identity = ExportIdentityColumn::tryFrom($key);

            if ($identity !== null) {
                $descriptors[] = [
                    'type' => 'attribute',
                    'attr' => $identity->value,
                    'header' => $this->uniqueHeader($identity->label(), $takenHeaders),
                ];

                continue;
            }

            $field = $fieldsByKey->get($key);

            if (!$field instanceof FieldDefinition) {
                continue;
            }

            $header = $this->uniqueHeader($this->label($field), $takenHeaders);

            if ($this->isRelation($field->field_type)) {
                $descriptors[] = [
                    'type' => 'relation',
                    'header' => $header,
                    'relationshipTypeId' => $this->relationshipTypeId($user, $objectType, $field),
                ];

                continue;
            }

            $descriptors[] = ['type' => 'data', 'key' => $field->key, 'header' => $header];
        }

        $headers = array_map(
            static fn (array $descriptor): string => (string) $descriptor['header'],
            $descriptors,
        );

        return ['headers' => $headers, 'descriptors' => $descriptors];
    }

    /**
     * @param  array{headers: list<string>, descriptors: list<array<string, mixed>>}  $plan
     * @param  Collection<int, CustomRecord>  $records
     * @return list<array<string, mixed>>
     */
    public function rowsForChunk(array $plan, Collection $records, string $tenantId): array
    {
        $linkRows = $this->linkRowsForChunk($plan['descriptors'], $records, $tenantId);
        $links = $this->groupLinks($linkRows);
        $targets = $this->targetsForLinks($linkRows, $tenantId);

        $rows = [];

        foreach ($records as $record) {
            $recordId = (string) $record->getKey();
            $data = is_array($record->data) ? $record->data : [];
            $row = [];

            foreach ($plan['descriptors'] as $descriptor) {
                $header = (string) $descriptor['header'];
                $row[$header] = $this->cell($descriptor, $record, $data, $links[$recordId] ?? [], $targets);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $descriptor
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $recordLinks
     * @param  array<string, Model>  $targets
     */
    private function cell(array $descriptor, CustomRecord $record, array $data, array $recordLinks, array $targets): mixed
    {
        $type = (string) $descriptor['type'];

        if ($type === 'attribute') {
            return $record->{(string) $descriptor['attr']} ?? null;
        }

        if ($type === 'data') {
            return $data[(string) $descriptor['key']] ?? null;
        }

        $relationshipTypeId = $descriptor['relationshipTypeId'] ?? null;

        if (!is_string($relationshipTypeId)) {
            return null;
        }

        $references = [];

        foreach ($recordLinks[$relationshipTypeId] ?? [] as $targetId) {
            $target = $targets[$targetId] ?? null;
            $reference = $target instanceof Model ? $this->exchangeIdentity->of($target) : null;

            if ($reference !== null) {
                $references[] = $reference;
            }
        }

        return $references === [] ? null : implode($this->relationValueSeparator, $references);
    }

    /**
     * @param  list<array<string, mixed>>  $descriptors
     * @param  Collection<int, CustomRecord>  $records
     * @return Collection<int, RecordLink>
     */
    private function linkRowsForChunk(array $descriptors, Collection $records, string $tenantId): Collection
    {
        $relationshipTypeIds = [];

        foreach ($descriptors as $descriptor) {
            $relationshipTypeId = $descriptor['relationshipTypeId'] ?? null;

            if (is_string($relationshipTypeId)) {
                $relationshipTypeIds[$relationshipTypeId] = true;
            }
        }

        if ($relationshipTypeIds === [] || $records->isEmpty()) {
            return new Collection;
        }

        $recordIds = $records->map(static fn (CustomRecord $record): string => (string) $record->getKey())->all();

        return RecordLink::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('from_record_id', $recordIds)
            ->whereIn('relationship_type_id', array_keys($relationshipTypeIds))
            ->orderBy('position')
            ->get(['from_record_id', 'relationship_type_id', 'to_record_type', 'to_record_id']);
    }

    /**
     * @param  Collection<int, RecordLink>  $linkRows
     * @return array<string, array<string, list<string>>>
     */
    private function groupLinks(Collection $linkRows): array
    {
        /** @var array<string, array<string, list<string>>> $links */
        $links = [];

        foreach ($linkRows as $link) {
            $from = $link->from_record_id;
            $relationshipTypeId = $link->relationship_type_id;
            $links[$from][$relationshipTypeId][] = $link->to_record_id;
        }

        return $links;
    }

    /**
     * @param  Collection<int, RecordLink>  $linkRows
     * @return array<string, Model>
     */
    private function targetsForLinks(Collection $linkRows, string $tenantId): array
    {
        /** @var array<string, list<string>> $keysByType */
        $keysByType = [];

        foreach ($linkRows as $link) {
            $keysByType[(string) $link->to_record_type][] = $link->to_record_id;
        }

        $targets = [];

        foreach ($keysByType as $objectTypeId => $keys) {
            $targets = [
                ...$targets,
                ...$this->exchangeIdentity->rowsByKey($objectTypeId, array_values(array_unique($keys)), $tenantId),
            ];
        }

        return $targets;
    }

    /**
     * @param  Collection<int, FieldDefinition>  $fields
     * @return list<string>
     */
    private function defaultKeyOrder(Collection $fields): array
    {
        $keys = $fields
            ->sortBy([
                ['list_position', 'asc'],
                ['key', 'asc'],
            ])
            ->map(static fn (FieldDefinition $field): string => $field->key)
            ->values()
            ->all();

        return [
            ExportIdentityColumn::ExternalReferenceId->value,
            ExportIdentityColumn::RecordNumber->value,
            ...$keys,
        ];
    }

    private function label(FieldDefinition $field): string
    {
        $label = $this->labels->resolve($field->i18n_labels);

        return is_string($label) && $label !== '' ? $label : $field->key;
    }

    private function relationshipTypeId(User $user, ObjectType $objectType, FieldDefinition $field): ?string
    {
        $config = $field->config;
        $relationshipTypeId = is_array($config) ? ($config['relationship_type_id'] ?? null) : null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            return null;
        }

        $relationshipType = $this->fieldLookup->relationship($relationshipTypeId);

        if (!$relationshipType instanceof RelationshipType) {
            return null;
        }

        $counterpartId = $relationshipType->from_object_type_id === (string) $objectType->getKey()
            ? $relationshipType->to_object_type_id
            : $relationshipType->from_object_type_id;

        $counterpart = $this->objectTypes->find($counterpartId);

        return $counterpart instanceof ObjectType && $user->hasPermission("{$counterpart->slug}.view")
            ? $relationshipTypeId
            : null;
    }

    private function isRelation(FieldType $fieldType): bool
    {
        return $fieldType === FieldType::RelationHasMany || $fieldType === FieldType::RelationManyToMany;
    }
}
