import { isPlainRecord } from '@/shared/lib/guards';

export type Combinator = 'and' | 'or';

export type FilterValue = string | number | boolean | null | Array<string | number | boolean>;

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

export type FilterConditionPatch = Partial<Pick<FilterTreeCondition, 'field' | 'operator' | 'value' | 'valueTo'>>;

export function hasFilterConditions(value: unknown): value is FilterGroupNode {
  return isPlainRecord(value) && Array.isArray(value.conditions);
}

export function isFilterGroupNode(value: unknown): value is FilterGroupNode {
  return hasFilterConditions(value) && (value.combinator === 'and' || value.combinator === 'or');
}
