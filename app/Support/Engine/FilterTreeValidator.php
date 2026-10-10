<?php

declare(strict_types=1);

namespace App\Support\Engine;

use App\Enums\CustomFields\FilterOperator;
use App\Exceptions\Engine\InvalidFilterTreeException;
use App\Models\FieldDefinition;
use App\Models\ObjectType;
use App\Models\User;
use App\Support\Authorization\FieldVisibilityResolver;
use App\Support\CustomFields\FieldTypeRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class FilterTreeValidator
{
    private int $maxDepth = 5;

    private int $maxConditions = 50;

    private string $keyPattern = '/^[a-z][a-z0-9_]*$/';

    /**
     * @var list<string>
     */
    private array $valuelessOperators = ['blank', 'notBlank', 'has', 'hasNot'];

    public function __construct(private readonly FieldTypeRegistry $fieldTypeRegistry) {}

    /**
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, mixed>  $tree
     *
     * @throws InvalidFilterTreeException
     * @throws AuthorizationException
     */
    public function validate(
        array $tree,
        Collection $allowedFields,
        FieldVisibilityResolver $fieldVisibility,
        ObjectType $objectType,
    ): void {
        if ($tree === []) {
            return;
        }

        $conditionCount = 0;
        $this->validateStructure($tree, 1, $conditionCount);

        [$forbidden, $fieldMap] = $this->authorizationContext($fieldVisibility, $objectType);
        $this->validateSemantics($tree, $allowedFields, $forbidden, $fieldMap);
    }

    /**
     * @param  iterable<int, mixed>  $sorts
     * @param  Collection<int, FieldDefinition>  $allowedFields
     *
     * @throws InvalidFilterTreeException
     * @throws AuthorizationException
     */
    public function validateSort(
        iterable $sorts,
        Collection $allowedFields,
        FieldVisibilityResolver $fieldVisibility,
        ObjectType $objectType,
    ): void {
        [$forbidden, $fieldMap] = $this->authorizationContext($fieldVisibility, $objectType);

        foreach ($sorts as $sort) {
            if (!is_array($sort)) {
                throw new InvalidFilterTreeException;
            }

            $key = $sort['colId'] ?? null;

            if (!is_string($key) || preg_match($this->keyPattern, $key) !== 1) {
                throw new InvalidFilterTreeException;
            }

            $definition = $fieldMap->get($key);

            $this->assertReadable($key, $definition, $forbidden);

            if (!$definition instanceof FieldDefinition || !$definition->is_sortable) {
                throw new InvalidFilterTreeException;
            }
        }
    }

    /**
     * @throws InvalidFilterTreeException
     */
    private function validateStructure(mixed $node, int $depth, int &$conditionCount): void
    {
        if (!is_array($node)) {
            throw new InvalidFilterTreeException;
        }

        if (array_key_exists('conditions', $node) || array_key_exists('combinator', $node)) {
            if ($depth > $this->maxDepth) {
                throw new InvalidFilterTreeException;
            }

            if (!in_array($node['combinator'] ?? null, ['and', 'or'], true)) {
                throw new InvalidFilterTreeException;
            }

            $conditions = $node['conditions'] ?? null;

            if (!is_array($conditions) || !array_is_list($conditions)) {
                throw new InvalidFilterTreeException;
            }

            foreach ($conditions as $child) {
                $this->validateStructure($child, $depth + 1, $conditionCount);
            }

            return;
        }

        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;

        if (!is_string($field) || !is_string($operator)) {
            throw new InvalidFilterTreeException;
        }

        $conditionCount++;

        if ($conditionCount > $this->maxConditions) {
            throw new InvalidFilterTreeException;
        }

        if (preg_match($this->keyPattern, $field) !== 1) {
            throw new InvalidFilterTreeException;
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, int>  $forbidden
     * @param  Collection<string, FieldDefinition>  $fieldMap
     *
     * @throws InvalidFilterTreeException
     * @throws AuthorizationException
     */
    private function validateSemantics(array $node, Collection $allowedFields, array $forbidden, Collection $fieldMap): void
    {
        if (array_key_exists('conditions', $node)) {
            /** @var array<string, mixed> $child */
            foreach ($node['conditions'] as $child) {
                $this->validateSemantics($child, $allowedFields, $forbidden, $fieldMap);
            }

            return;
        }

        $this->validateCondition($node, $allowedFields, $forbidden, $fieldMap);
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  Collection<int, FieldDefinition>  $allowedFields
     * @param  array<string, int>  $forbidden
     * @param  Collection<string, FieldDefinition>  $fieldMap
     *
     * @throws InvalidFilterTreeException
     * @throws AuthorizationException
     */
    private function validateCondition(array $condition, Collection $allowedFields, array $forbidden, Collection $fieldMap): void
    {
        /** @var string $key */
        $key = $condition['field'];
        /** @var string $operator */
        $operator = $condition['operator'];

        $definition = $fieldMap->get($key);

        $this->assertReadable($key, $definition, $forbidden);

        if ($definition instanceof FieldDefinition && !$definition->is_filterable) {
            throw new AuthorizationException($this->rightsMessage());
        }

        $field = $allowedFields->firstWhere('key', $key);

        if (!$field instanceof FieldDefinition) {
            throw new InvalidFilterTreeException;
        }

        $allowedOperators = array_map(
            static fn (FilterOperator $candidate): string => $candidate->value,
            $this->fieldTypeRegistry->filterOperators($field),
        );

        if (!in_array($operator, $allowedOperators, true)) {
            throw new InvalidFilterTreeException;
        }

        $this->assertRelationshipTarget($operator, $field);

        $this->assertArity($operator, $condition);
    }

    /**
     * @throws InvalidFilterTreeException
     */
    private function assertRelationshipTarget(string $operator, FieldDefinition $field): void
    {
        if ($operator !== FilterOperator::Has->value && $operator !== FilterOperator::HasNot->value) {
            return;
        }

        $config = is_array($field->config) ? $field->config : [];
        $relationshipTypeId = $config['relationship_type_id'] ?? null;

        if (!is_string($relationshipTypeId) || $relationshipTypeId === '') {
            throw new InvalidFilterTreeException;
        }
    }

    /**
     * @param  array<string, int>  $forbidden
     *
     * @throws AuthorizationException
     */
    private function assertReadable(string $key, ?FieldDefinition $definition, array $forbidden): void
    {
        if (isset($forbidden[$key]) || ($definition instanceof FieldDefinition && $definition->is_encrypted)) {
            throw new AuthorizationException($this->rightsMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $condition
     *
     * @throws InvalidFilterTreeException
     */
    private function assertArity(string $operator, array $condition): void
    {
        if (in_array($operator, $this->valuelessOperators, true)) {
            return;
        }

        if ($operator === 'inRange') {
            if (!array_key_exists('value', $condition) || !array_key_exists('valueTo', $condition)) {
                throw new InvalidFilterTreeException;
            }

            return;
        }

        if ($operator === 'in' || $operator === 'notIn') {
            if (!isset($condition['value']) || !is_array($condition['value'])) {
                throw new InvalidFilterTreeException;
            }

            return;
        }

        if (!array_key_exists('value', $condition)) {
            throw new InvalidFilterTreeException;
        }
    }

    /**
     * @return array{0: array<string, int>, 1: Collection<string, FieldDefinition>}
     *
     * @throws AuthorizationException
     */
    private function authorizationContext(FieldVisibilityResolver $fieldVisibility, ObjectType $objectType): array
    {
        $viewer = Auth::user();

        if (!$viewer instanceof User) {
            throw new AuthorizationException($this->rightsMessage());
        }

        $objectTypeId = (string) $objectType->getKey();

        $forbidden = array_flip($fieldVisibility->forbiddenReadFieldKeys($viewer, $objectTypeId));

        $objectType->loadMissing('fieldDefinitions');

        /** @var Collection<string, FieldDefinition> $fieldMap */
        $fieldMap = $objectType->fieldDefinitions->keyBy('key');

        return [$forbidden, $fieldMap];
    }

    private function rightsMessage(): string
    {
        return __('i18n.backend.support.engine.filter_tree_validator.you_may_not_filter_or_sort_by_one_of');
    }
}
