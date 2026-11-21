import { mount } from '@vue/test-utils';
import type { IsFullWidthRowParams } from 'ag-grid-community';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Component } from 'vue';
import { nextTick } from 'vue';
import FieldDefinitionsController from '@/actions/App/Http/Controllers/Engine/FieldDefinitionsController';
import type { FieldGridRow, FieldGroupRow } from '@/domains/business-data/ui-record-field/types/fieldGroups';
import { isFieldGroupHeaderRow } from '@/domains/business-data/ui-record-field/types/fieldGroups';
import type { ObjectTypeFieldRow } from '@/domains/business-data/util-record-format/types/formulas';
import { gridColumns, gridOf, gridProp, gridRows } from '@/shared/testing/dataGrid';
import ObjectTypeFieldGrid from './ObjectTypeFieldGrid.vue';

const { putMock } = vi.hoisted(() => ({ putMock: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
  router: { put: putMock, delete: vi.fn() },
}));

const { DataGridStub } = vi.hoisted(() => ({
  DataGridStub: {
    name: 'DataGrid',
    props: ['rowData', 'columnDefs', 'getRowId', 'isFullWidthRow', 'fullWidthCellRenderer'],
    template: '<div data-grid-stub />',
  },
}));

vi.mock('@/shared/ui/data-grid/DataGrid.vue', () => ({
  default: DataGridStub,
}));

function field(key: string, fieldGroupId: string | null = null): ObjectTypeFieldRow {
  return {
    id: `fd-${key}`,
    field_group_id: fieldGroupId,
    key,
    field_type: 'text_short',
    label: key,
    description: null,
    is_required: false,
    is_unique: false,
    is_searchable: false,
    is_translatable: false,
    is_encrypted: false,
    is_sortable: false,
    is_filterable: false,
    is_default_column: false,
    is_card_field: false,
    is_reserved: false,
    is_type_changeable: true,
    list_position: null,
    config: null,
    validation_rules: null,
    default_value: null,
  };
}

const address: FieldGroupRow = {
  id: 'fg-address',
  key: 'address',
  label: 'Adresse',
  description: null,
  position: 1,
};

function mountGrid(groups: FieldGroupRow[] = [address]) {
  return mount(ObjectTypeFieldGrid as unknown as Component, {
    props: {
      objectTypeSlug: 'companies',
      fields: [field('name'), field('street', address.id)],
      fieldTypes: [{ value: 'text_short', label: 'Text Short' }],
      groups,
      editable: true,
    },
  });
}

type Wrapper = ReturnType<typeof mountGrid>;

function rows(wrapper: Wrapper): FieldGridRow[] {
  return gridRows(wrapper) as FieldGridRow[];
}

function keys(wrapper: Wrapper): string[] {
  return rows(wrapper).map((row) => (isFieldGroupHeaderRow(row) ? `# ${row.label}` : row.key));
}

function mountHeader(wrapper: Wrapper, row: FieldGridRow) {
  const renderer = gridProp(wrapper, 'fullWidthCellRenderer') as Component;

  return mount(renderer, { props: { params: { data: row } } });
}

describe('ObjectTypeFieldGrid — grouped rows', () => {
  it('feeds the grid a group header in front of every section', () => {
    expect(keys(mountGrid())).toEqual(['# Ohne Gruppe', 'name', '# Adresse', 'street']);
  });

  it('leaves the rows flat while the object type carries no group', () => {
    expect(keys(mountGrid([]))).toEqual(['name', 'street']);
  });

  it('marks exactly the header rows as full width', () => {
    const wrapper = mountGrid();
    const isFullWidthRow = gridProp(wrapper, 'isFullWidthRow') as (
      params: IsFullWidthRowParams<FieldGridRow>,
    ) => boolean;

    const verdicts = rows(wrapper).map((row) =>
      isFullWidthRow({
        rowNode: { data: row },
      } as IsFullWidthRowParams<FieldGridRow>),
    );

    expect(verdicts).toEqual([true, false, true, false]);
  });

  it('renders the label and the member count on the header', () => {
    const wrapper = mountGrid();
    const header = mountHeader(wrapper, rows(wrapper)[2]);

    expect(header.text()).toContain('Adresse');
    expect(header.text()).toContain('1');
    expect(header.attributes('aria-expanded')).toBe('true');
  });

  it('folds a group away when its header is clicked and unfolds it again', async () => {
    const wrapper = mountGrid();

    await mountHeader(wrapper, rows(wrapper)[2]).trigger('click');
    await nextTick();

    expect(keys(wrapper)).toEqual(['# Ohne Gruppe', 'name', '# Adresse']);

    await mountHeader(wrapper, rows(wrapper)[2]).trigger('click');
    await nextTick();

    expect(keys(wrapper)).toEqual(['# Ohne Gruppe', 'name', '# Adresse', 'street']);
  });

  it('gives the grid a row id for header rows as well as field rows', () => {
    const wrapper = mountGrid();
    const getRowId = gridProp(wrapper, 'getRowId') as (params: { data: FieldGridRow }) => string;

    expect(rows(wrapper).map((row) => getRowId({ data: row }))).toEqual([
      'group:ungrouped',
      'fd-name',
      'group:fg-address',
      'fd-street',
    ]);
  });
});

describe('ObjectTypeFieldGrid — the description column', () => {
  beforeEach(() => {
    putMock.mockClear();
  });

  it('offers the description as an editable column', () => {
    const column = gridColumns(mountGrid()).find((entry) => entry.field === 'description');

    expect(column?.headerName).toBe('Beschreibung');
    expect(column?.editable).toBe(true);
  });

  it('saves an edited description straight away', () => {
    const wrapper = mountGrid();

    gridOf(wrapper).vm.$emit('cell-value-changed', {
      colDef: { field: 'description' },
      data: rows(wrapper)[1],
      newValue: 'Der Anzeigename.',
    });

    expect(putMock).toHaveBeenCalledWith(
      FieldDefinitionsController.update.url({
        objectType: 'companies',
        field: 'fd-name',
      }),
      { description: 'Der Anzeigename.' },
      expect.anything(),
    );
  });

  it('ignores a change on a column that is not saved inline', () => {
    const wrapper = mountGrid();

    gridOf(wrapper).vm.$emit('cell-value-changed', {
      colDef: { field: 'key' },
      data: rows(wrapper)[1],
      newValue: 'renamed',
    });

    expect(putMock).not.toHaveBeenCalled();
  });
});
