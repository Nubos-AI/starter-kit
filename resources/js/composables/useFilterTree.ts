import { computed, inject, provide, ref } from 'vue';
import type { ComputedRef, InjectionKey, Ref } from 'vue';
import { filterOperatorsFor } from '@/composables/useFieldTypeRegistry';
import type { OperatorArity } from '@/composables/useFieldTypeRegistry';
import type { FieldDefinition } from '@/types/fields';

export type Combinator = 'and' | 'or';

export type FilterValue =
    | string
    | number
    | boolean
    | null
    | Array<string | number | boolean>;

export interface FilterConditionNode {
    field: string;
    operator: string;
    value?: FilterValue;
    valueTo?: FilterValue;
}

export interface FilterGroupNode {
    combinator: Combinator;
    conditions: (FilterGroupNode | FilterConditionNode)[];
}

export type FilterNode = FilterGroupNode | FilterConditionNode;

export interface FilterTreeCondition {
    id: string;
    kind: 'condition';
    field: string;
    operator: string;
    value?: FilterValue;
    valueTo?: FilterValue;
}

export interface FilterTreeGroup {
    id: string;
    kind: 'group';
    combinator: Combinator;
    conditions: FilterTreeNode[];
}

export type FilterTreeNode = FilterTreeGroup | FilterTreeCondition;

export type FilterConditionPatch = Partial<
    Pick<FilterTreeCondition, 'field' | 'operator' | 'value' | 'valueTo'>
>;

export const MAX_DEPTH = 5;
export const MAX_NODES = 50;

export interface FilterTreeContext {
    fields: FieldDefinition[];
    maxDepth: number;
    maxNodes: number;
    nodeCount: ComputedRef<number>;
    addCondition: (groupId: string) => void;
    addGroup: (groupId: string) => void;
    removeNode: (id: string) => void;
    setCombinator: (groupId: string, combinator: Combinator) => void;
    updateCondition: (id: string, patch: FilterConditionPatch) => void;
}

export const filterTreeKey: InjectionKey<FilterTreeContext> =
    Symbol('filterTree');

export function useFilterTreeContext(): FilterTreeContext {
    const context = inject(filterTreeKey);

    if (!context) {
        throw new Error('FilterTree context is not available.');
    }

    return context;
}

export type EffectiveArity = 'none' | 'single' | 'multi' | 'range';

const multiValueOperators: ReadonlySet<string> = new Set(['in', 'notIn']);
const valuelessOperators: ReadonlySet<string> = new Set(['has', 'hasNot']);

function arityFor(
    fields: FieldDefinition[],
    field: string,
    operator: string,
): OperatorArity {
    const definition = fields.find((candidate) => candidate.key === field);

    if (!definition) {
        return 'none';
    }

    return (
        filterOperatorsFor(definition.field_type).find(
            (option) => option.value === operator,
        )?.arity ?? 'none'
    );
}

export function effectiveArityFor(
    fields: FieldDefinition[],
    field: string,
    operator: string,
): EffectiveArity {
    if (valuelessOperators.has(operator)) {
        return 'none';
    }

    if (multiValueOperators.has(operator)) {
        return 'multi';
    }

    return arityFor(fields, field, operator);
}

function toValueArray(
    raw: FilterValue | undefined,
): Array<string | number | boolean> {
    if (Array.isArray(raw)) {
        return raw;
    }

    if (typeof raw === 'string') {
        return raw
            .split(',')
            .map((part) => part.trim())
            .filter((part) => part.length > 0);
    }

    if (raw === undefined || raw === null) {
        return [];
    }

    return [raw];
}

export function useFilterTree(
    fields: FieldDefinition[],
    initial?: FilterGroupNode,
) {
    let sequence = 0;

    function nextId(): string {
        sequence += 1;

        return `node-${sequence}`;
    }

    function emptyGroup(): FilterTreeGroup {
        return {
            id: nextId(),
            kind: 'group',
            combinator: 'and',
            conditions: [],
        };
    }

    function hydrateCondition(seed: FilterConditionNode): FilterTreeCondition {
        const node: FilterTreeCondition = {
            id: nextId(),
            kind: 'condition',
            field: seed.field,
            operator: seed.operator,
        };

        if ('value' in seed) {
            node.value = seed.value;
        }

        if ('valueTo' in seed) {
            node.valueTo = seed.valueTo;
        }

        return node;
    }

    function hydrateGroup(seed: FilterGroupNode): FilterTreeGroup {
        return {
            id: nextId(),
            kind: 'group',
            combinator: seed.combinator,
            conditions: seed.conditions.map((child) =>
                'combinator' in child
                    ? hydrateGroup(child)
                    : hydrateCondition(child),
            ),
        };
    }

    function defaultCondition(): FilterTreeCondition {
        const field = fields[0];
        const operator = field
            ? (filterOperatorsFor(field.field_type)[0]?.value ?? '')
            : '';

        return {
            id: nextId(),
            kind: 'condition',
            field: field?.key ?? '',
            operator,
        };
    }

    const root: Ref<FilterTreeGroup> = ref(
        hydrateGroup(initial ?? { combinator: 'and', conditions: [] }),
    );

    function findGroup(
        id: string,
        node: FilterTreeGroup,
    ): FilterTreeGroup | null {
        if (node.id === id) {
            return node;
        }

        for (const child of node.conditions) {
            if (child.kind === 'group') {
                const found = findGroup(id, child);

                if (found) {
                    return found;
                }
            }
        }

        return null;
    }

    function findCondition(
        id: string,
        node: FilterTreeGroup,
    ): FilterTreeCondition | null {
        for (const child of node.conditions) {
            if (child.kind === 'condition' && child.id === id) {
                return child;
            }

            if (child.kind === 'group') {
                const found = findCondition(id, child);

                if (found) {
                    return found;
                }
            }
        }

        return null;
    }

    function countConditions(node: FilterTreeGroup): number {
        return node.conditions.reduce(
            (total, child) =>
                total + (child.kind === 'group' ? countConditions(child) : 1),
            0,
        );
    }

    const nodeCount = computed(() => countConditions(root.value));

    function addCondition(groupId: string): void {
        const group = findGroup(groupId, root.value);

        if (group) {
            group.conditions.push(defaultCondition());
        }
    }

    function addGroup(groupId: string): void {
        const group = findGroup(groupId, root.value);

        if (group) {
            group.conditions.push(emptyGroup());
        }
    }

    function removeNode(id: string): void {
        function removeFrom(group: FilterTreeGroup): boolean {
            const index = group.conditions.findIndex(
                (child) => child.id === id,
            );

            if (index !== -1) {
                group.conditions.splice(index, 1);

                return true;
            }

            return group.conditions.some(
                (child) => child.kind === 'group' && removeFrom(child),
            );
        }

        removeFrom(root.value);
    }

    function setCombinator(groupId: string, combinator: Combinator): void {
        const group = findGroup(groupId, root.value);

        if (group) {
            group.combinator = combinator;
        }
    }

    function updateCondition(id: string, patch: FilterConditionPatch): void {
        const condition = findCondition(id, root.value);

        if (!condition) {
            return;
        }

        if (patch.field !== undefined) {
            condition.field = patch.field;
        }

        if (patch.operator !== undefined) {
            condition.operator = patch.operator;
        }

        if ('value' in patch) {
            condition.value = patch.value;
        }

        if ('valueTo' in patch) {
            condition.valueTo = patch.valueTo;
        }
    }

    function reset(): void {
        root.value = emptyGroup();
    }

    function serializeCondition(
        node: FilterTreeCondition,
    ): FilterConditionNode {
        const serialized: FilterConditionNode = {
            field: node.field,
            operator: node.operator,
        };
        const arity = effectiveArityFor(fields, node.field, node.operator);

        if (arity === 'multi') {
            serialized.value = toValueArray(node.value);
        } else if (arity !== 'none' && node.value !== undefined) {
            serialized.value = node.value;
        }

        if (arity === 'range' && node.valueTo !== undefined) {
            serialized.valueTo = node.valueTo;
        }

        return serialized;
    }

    function isValuePresent(raw: unknown): boolean {
        return raw !== undefined && raw !== null && raw !== '';
    }

    function conditionIsComplete(node: FilterTreeCondition): boolean {
        const arity = effectiveArityFor(fields, node.field, node.operator);

        if (arity === 'none') {
            return true;
        }

        if (arity === 'multi') {
            return toValueArray(node.value).length > 0;
        }

        if (arity === 'range') {
            return isValuePresent(node.value) && isValuePresent(node.valueTo);
        }

        return isValuePresent(node.value);
    }

    function serializeGroup(node: FilterTreeGroup): FilterGroupNode {
        const conditions: (FilterGroupNode | FilterConditionNode)[] = [];

        for (const child of node.conditions) {
            if (child.kind === 'group') {
                const serialized = serializeGroup(child);

                if (serialized.conditions.length > 0) {
                    conditions.push(serialized);
                }

                continue;
            }

            if (conditionIsComplete(child)) {
                conditions.push(serializeCondition(child));
            }
        }

        return {
            combinator: node.combinator,
            conditions,
        };
    }

    function serialize(): FilterGroupNode {
        return serializeGroup(root.value);
    }

    function provideContext(): void {
        provide(filterTreeKey, {
            fields,
            maxDepth: MAX_DEPTH,
            maxNodes: MAX_NODES,
            nodeCount,
            addCondition,
            addGroup,
            removeNode,
            setCombinator,
            updateCondition,
        });
    }

    return {
        root,
        nodeCount,
        addCondition,
        addGroup,
        removeNode,
        setCombinator,
        updateCondition,
        reset,
        serialize,
        provideContext,
    };
}
