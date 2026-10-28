<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\DTOs\Reports\ReportDefinitionData;
use App\DTOs\Reports\ReportLinkedFieldBinding;
use App\Enums\Reports\AggregationType;
use App\Enums\Reports\GroupingBucket;
use App\Enums\Reports\ReportNotExecutableReason;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Exceptions\Reports\ReportNotExecutableException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\Engine\FilterFieldKeyCollector;
use App\Support\Engine\FilterTreeValidator;
use App\Support\Engine\ObjectTypeRegistry;
use App\Support\Engine\SystemFilterFields;
use Illuminate\Auth\Access\AuthorizationException;

class ReportDefinitionValidator
{
    public function __construct(
        private readonly FilterTreeValidator $filterTreeValidator,
        private readonly FilterFieldKeyCollector $filterFieldKeyCollector,
        private readonly FieldVisibilityResolver $fieldVisibility,
        private readonly SystemFilterFields $systemFilterFields,
        private readonly ReportLinkedFieldResolver $linkedFields,
        private readonly ObjectTypeRegistry $objectTypes,
        private readonly ReportFieldSource $fieldSource,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws ReportNotExecutableException
     */
    public function validate(array $definition, ObjectType $objectType, User $viewer): ReportDefinitionData
    {
        $filterTree = $definition['filter_definition'] ?? [];

        if (!is_array($filterTree)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::MalformedDefinition);
        }

        /** @var array<string, mixed> $filterTree */
        $aggregation = $this->aggregation($definition['aggregation_type'] ?? null);
        $groupByBucket = $this->bucket($definition['group_by_bucket'] ?? null);

        $aggregationFieldKey = $this->fieldKey($definition['aggregation_field_key'] ?? null);
        $groupByFieldKey = $this->fieldKey($definition['group_by_field_key'] ?? null);
        $seriesFieldKey = $this->fieldKey($definition['series_field_key'] ?? null);

        if ($aggregation !== AggregationType::Count && $aggregationFieldKey === null) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::MalformedDefinition);
        }

        if ($groupByFieldKey === null && ($groupByBucket !== null || $seriesFieldKey !== null)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::MalformedDefinition);
        }

        if ($seriesFieldKey !== null && $this->linkedFields->isQualified($seriesFieldKey)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnsupportedGrouping);
        }

        $groupByLink = $groupByFieldKey !== null && $this->linkedFields->isQualified($groupByFieldKey)
            ? $this->linkedFields->resolve($groupByFieldKey, $objectType)
            : null;

        $aggregationLink = $aggregationFieldKey !== null && $this->linkedFields->isQualified($aggregationFieldKey)
            ? $this->linkedFields->resolve($aggregationFieldKey, $objectType)
            : null;

        if ($aggregationLink instanceof ReportLinkedFieldBinding && !$this->supportsLinkedAggregation($aggregation)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnsupportedAggregation);
        }

        $objectTypeId = (string) $objectType->getKey();

        $persistedFields = $this->fieldSource->persistedFields($objectTypeId);

        /** @var array<string, FieldDefinition> $persistedByKey */
        $persistedByKey = $persistedFields->keyBy('key')->all();

        /** @var array<string, FieldDefinition> $systemByKey */
        $systemByKey = $this->systemFilterFields->all($objectTypeId)->keyBy('key')->all();

        /** @var array<string, FieldDefinition> $fields */
        $fields = [];

        /** @var list<string> $systemFieldKeys */
        $systemFieldKeys = [];

        $namedKeys = [
            $aggregationLink instanceof ReportLinkedFieldBinding ? $aggregationLink->relationField->key : $aggregationFieldKey,
            $groupByLink instanceof ReportLinkedFieldBinding ? $groupByLink->relationField->key : $groupByFieldKey,
            $seriesFieldKey,
        ];

        foreach ($this->referencedKeys($namedKeys, $filterTree) as $key) {
            if ($this->systemFilterFields->isAgingField($key)) {
                throw new ReportNotExecutableException(ReportNotExecutableReason::FieldNotFilterable);
            }

            $field = $persistedByKey[$key] ?? $systemByKey[$key] ?? null;

            if (!$field instanceof FieldDefinition) {
                throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
            }

            $fields[$key] = $field;

            if ($this->systemFilterFields->isSystemField($field)) {
                $systemFieldKeys[] = $key;
            }
        }

        foreach ($fields as $field) {
            if ($field->is_encrypted) {
                throw new ReportNotExecutableException(ReportNotExecutableReason::EncryptedField);
            }
        }

        $forbidden = $this->fieldVisibility->forbiddenReadFieldKeys($viewer, $objectTypeId);

        foreach (array_keys($fields) as $key) {
            if (in_array($key, $forbidden, true)) {
                throw new ReportNotExecutableException(ReportNotExecutableReason::FieldNotReadable);
            }
        }

        foreach ([$groupByLink, $aggregationLink] as $link) {
            if ($link instanceof ReportLinkedFieldBinding) {
                $this->assertLinkedFieldReadable($link, $viewer);
            }
        }

        $aggregationField = $this->slotField($aggregationLink, $aggregationFieldKey, $fields);

        if ($aggregationField instanceof FieldDefinition && !$aggregation->allowsFieldType($aggregationField->field_type)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnsupportedAggregation);
        }

        $groupByField = $this->slotField($groupByLink, $groupByFieldKey, $fields);

        if ($groupByBucket !== null && $groupByField instanceof FieldDefinition && !$groupByField->field_type->isTemporal()) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::UnsupportedGrouping);
        }

        $filterableFields = $persistedFields
            ->filter(static fn (FieldDefinition $field): bool => $field->is_filterable)
            ->values()
            ->toBase()
            ->concat($systemByKey)
            ->values();

        try {
            $this->filterTreeValidator->validate($filterTree, $filterableFields, $this->fieldVisibility, $objectType);
        } catch (AuthorizationException $exception) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::FieldNotFilterable, $exception);
        } catch (InvalidFilterTreeException $exception) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::InvalidFilterTree, $exception);
        }

        return new ReportDefinitionData(
            $objectTypeId,
            $aggregation,
            $aggregationField,
            $groupByField,
            $groupByBucket,
            $seriesFieldKey === null ? null : $fields[$seriesFieldKey],
            $filterTree,
            $fields,
            $systemFieldKeys,
            $groupByLink,
            $aggregationLink,
        );
    }

    /**
     * @param  list<?string>  $named
     * @param  array<string, mixed>  $filterTree
     * @return list<string>
     *
     * @throws ReportNotExecutableException
     */
    private function referencedKeys(array $named, array $filterTree): array
    {
        $filterKeys = $this->filterFieldKeyCollector->collect($filterTree);

        foreach ($filterKeys as $key) {
            if ($this->linkedFields->isQualified($key)) {
                throw new ReportNotExecutableException(ReportNotExecutableReason::UnknownField);
            }
        }

        return array_values(array_unique([
            ...array_filter($named, static fn (?string $key): bool => $key !== null),
            ...$filterKeys,
        ]));
    }

    /**
     * @param  array<string, FieldDefinition>  $fields
     */
    private function slotField(?ReportLinkedFieldBinding $link, ?string $key, array $fields): ?FieldDefinition
    {
        if ($link instanceof ReportLinkedFieldBinding) {
            return $link->linkedField;
        }

        return $key === null ? null : $fields[$key];
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function assertLinkedFieldReadable(ReportLinkedFieldBinding $link, User $viewer): void
    {
        if ($link->linkedField->is_encrypted) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::EncryptedField);
        }

        $linkedType = $this->objectTypes->find($link->linkedObjectTypeId);

        if (!$linkedType instanceof ObjectType || !$viewer->hasPermission("{$linkedType->slug}.view")) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::FieldNotReadable);
        }

        $forbidden = $this->fieldVisibility->forbiddenReadFieldKeys($viewer, $link->linkedObjectTypeId);

        if (in_array($link->linkedField->key, $forbidden, true)) {
            throw new ReportNotExecutableException(ReportNotExecutableReason::FieldNotReadable);
        }
    }

    private function supportsLinkedAggregation(AggregationType $aggregation): bool
    {
        return match ($aggregation) {
            AggregationType::Count, AggregationType::Sum,
            AggregationType::Min, AggregationType::Max => true,
            AggregationType::Avg, AggregationType::DistinctCount => false,
        };
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function aggregation(mixed $value): AggregationType
    {
        if ($value instanceof AggregationType) {
            return $value;
        }

        $aggregation = is_string($value) ? AggregationType::tryFrom($value) : null;

        return $aggregation ?? throw new ReportNotExecutableException(ReportNotExecutableReason::MalformedDefinition);
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function bucket(mixed $value): ?GroupingBucket
    {
        if ($value === null || $value instanceof GroupingBucket) {
            return $value;
        }

        $bucket = is_string($value) ? GroupingBucket::tryFrom($value) : null;

        return $bucket ?? throw new ReportNotExecutableException(ReportNotExecutableReason::MalformedDefinition);
    }

    /**
     * @throws ReportNotExecutableException
     */
    private function fieldKey(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new ReportNotExecutableException(ReportNotExecutableReason::MalformedDefinition);
    }
}
