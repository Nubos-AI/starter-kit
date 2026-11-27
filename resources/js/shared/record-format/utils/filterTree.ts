import type { FilterGroupNode } from '@/shared/types/filters';
import { isFilterGroupNode } from '@/shared/types/filters';

export function hydratableFilterTree(value: unknown): FilterGroupNode | undefined {
  return isFilterGroupNode(value) ? value : undefined;
}

export function normalizeFilterTree(tree: FilterGroupNode | null): FilterGroupNode | null {
  return tree !== null && carriesCondition(tree) ? tree : null;
}

function carriesCondition(node: FilterGroupNode): boolean {
  return node.conditions.some((child) => ('combinator' in child ? carriesCondition(child) : true));
}
