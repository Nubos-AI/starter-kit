import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import type { Component } from 'vue';
import { isRecord, recordOf } from '@/shared/lib/guards';
import type { RowAction } from '@/shared/types/rowAction';
import { ACTIONS_COL_ID } from '@/shared/ui/data-grid/actions';
import { SELECTION_COL_ID } from '@/shared/ui/data-grid/selectionColumn';
import { Checkbox } from '@/shared/ui/primitives/checkbox';

export function isComponent(value: unknown): value is Component {
  return isRecord(value) && (typeof value.setup === 'function' || typeof value.render === 'function');
}

export function isRowAction<Row>(value: unknown): value is RowAction<Row> {
  return (
    isRecord(value) &&
    typeof value.label === 'string' &&
    (value.testId === undefined || typeof value.testId === 'string')
  );
}

export function gridsOf(wrapper: VueWrapper): VueWrapper[] {
  return wrapper.findAllComponents({ name: 'DataGrid' });
}

export function gridOf(wrapper: VueWrapper, gridIndex?: number): VueWrapper {
  if (gridIndex === undefined) {
    return wrapper.findComponent({ name: 'DataGrid' });
  }

  const grid = gridsOf(wrapper).at(gridIndex);

  if (grid === undefined) {
    throw new Error(`Grid ${gridIndex} is missing.`);
  }

  return grid;
}

export function gridProp(wrapper: VueWrapper, key: string, gridIndex?: number): unknown {
  const props: Record<string, unknown> = gridOf(wrapper, gridIndex).props();

  return props[key];
}

export function gridColumns(wrapper: VueWrapper, gridIndex?: number): Record<string, unknown>[] {
  const columns: unknown = gridProp(wrapper, 'columnDefs', gridIndex);

  return Array.isArray(columns) ? columns.filter(isRecord) : [];
}

export function gridRows(wrapper: VueWrapper): unknown[] {
  const rows: unknown = gridProp(wrapper, 'rowData');

  return Array.isArray(rows) ? rows : [];
}

export function gridColumn(wrapper: VueWrapper, colId: string): Record<string, unknown> {
  const column = gridColumns(wrapper).find((entry) => entry.colId === colId);

  if (column === undefined) {
    throw new Error(`Column ${colId} is missing.`);
  }

  return column;
}

export function columnParams(wrapper: VueWrapper, colId: string): Record<string, unknown> {
  return recordOf(gridColumn(wrapper, colId).cellRendererParams);
}

export function gridColumnByHeader(wrapper: VueWrapper, header: string, gridIndex?: number): Record<string, unknown> {
  const column = gridColumns(wrapper, gridIndex).find((entry) => entry.headerName === header);

  if (column === undefined) {
    throw new Error(`Column "${header}" is missing.`);
  }

  return column;
}

export function rowActions<Row>(wrapper: VueWrapper): RowAction<Row>[] {
  const actions = columnParams(wrapper, ACTIONS_COL_ID).actions;

  return Array.isArray(actions) ? actions.filter(isRowAction<Row>) : [];
}

export function rowAction<Row>(wrapper: VueWrapper, label: string): RowAction<Row> {
  const found = rowActions<Row>(wrapper).find((entry) => entry.label === label);

  if (found === undefined) {
    throw new Error(`Action "${label}" not found.`);
  }

  return found;
}

export function selectionCell(wrapper: VueWrapper): Component {
  const renderer = gridColumn(wrapper, SELECTION_COL_ID).cellRenderer;

  if (!isComponent(renderer)) {
    throw new Error('The selection column renders no cell.');
  }

  return renderer;
}

export function rowActionByTestId<Row>(wrapper: VueWrapper, testId: string): RowAction<Row> {
  const found = rowActions<Row>(wrapper).find((entry) => entry.testId === testId);

  if (found === undefined) {
    throw new Error(`Action "${testId}" not found.`);
  }

  return found;
}

export function selectionCheckbox(
  wrapper: VueWrapper,
  data: unknown,
  stubs: Record<string, Component | boolean> = {},
): VueWrapper {
  return mount(selectionCell(wrapper), {
    props: { params: { data } },
    global: { stubs },
  }).findComponent(Checkbox);
}

export async function selectRow(
  wrapper: VueWrapper,
  data: unknown,
  stubs: Record<string, Component | boolean> = {},
): Promise<void> {
  selectionCheckbox(wrapper, data, stubs).vm.$emit('update:modelValue', true);

  await nextTick();
}

export function activateRow(wrapper: VueWrapper, data: unknown): void {
  gridOf(wrapper).vm.$emit('row-activate', data);
}

export function rowActivation<Row>(wrapper: VueWrapper): (row: Row) => boolean {
  const guard: unknown = gridProp(wrapper, 'isRowActivatable');

  if (typeof guard !== 'function') {
    throw new Error('The grid has no row activation guard.');
  }

  return (row: Row): boolean => guard(row) === true;
}

export function columnValue(wrapper: VueWrapper, colId: string, data: unknown): unknown {
  const column = gridColumn(wrapper, colId);

  return typeof column.valueGetter === 'function' ? column.valueGetter({ data }) : undefined;
}

export function columnText(wrapper: VueWrapper, colId: string, data: unknown): unknown {
  const column = gridColumn(wrapper, colId);

  return typeof column.valueFormatter === 'function'
    ? column.valueFormatter({
        data,
        value: columnValue(wrapper, colId, data),
      })
    : undefined;
}

export function columnCell(wrapper: VueWrapper, colId: string): Component {
  const renderer = gridColumn(wrapper, colId).cellRenderer;

  if (!isComponent(renderer)) {
    throw new Error(`Column ${colId} renders no cell.`);
  }

  return renderer;
}
