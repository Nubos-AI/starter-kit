import type {
    ColDef,
    ICellRendererParams,
    IHeaderParams,
} from 'ag-grid-community';
import { defineComponent, h } from 'vue';
import type { PropType } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import type { ListSelection } from '@/composables/useListSelection';

export const SELECTION_COL_ID = '__list_select__';

interface VisibleRows {
    selectableIds: string[];
    total: number;
}

function visibleRows<TRow extends { id: string }>(
    params: IHeaderParams<TRow> | ICellRendererParams<TRow>,
    isSelectable: (row: TRow) => boolean,
): VisibleRows {
    const selectableIds: string[] = [];
    let total = 0;

    params.api.forEachNode((node) => {
        const row = node.data as TRow | undefined;

        if (row?.id === undefined || row.id === null) {
            return;
        }

        total += 1;

        if (isSelectable(row)) {
            selectableIds.push(row.id);
        }
    });

    return { selectableIds, total };
}

export function selectionColumn<TRow extends { id: string }>(
    selection: ListSelection,
    isSelectable: (row: TRow) => boolean = () => true,
): ColDef<TRow> {
    const CellRenderer = defineComponent({
        name: 'ListSelectionCell',
        props: {
            params: {
                type: Object as PropType<ICellRendererParams<TRow>>,
                required: true,
            },
        },
        setup(cellProps) {
            return () => {
                const row = cellProps.params.data ?? null;
                const id = row?.id ?? null;

                if (row === null || id === null || !isSelectable(row)) {
                    return null;
                }

                return h(Checkbox, {
                    modelValue: selection.isSelected(id),
                    'onUpdate:modelValue': () => selection.toggle(id),
                    'aria-label': 'Zeile auswählen',
                });
            };
        },
    });

    const HeaderRenderer = defineComponent({
        name: 'ListSelectionHeader',
        props: {
            params: {
                type: Object as PropType<IHeaderParams<TRow>>,
                required: true,
            },
        },
        setup(headerProps) {
            const loaded = (): VisibleRows =>
                visibleRows(headerProps.params, isSelectable);

            return () => {
                const { selectableIds, total } = loaded();

                return h(Checkbox, {
                    modelValue: selection.headerState(selectableIds, total),
                    'onUpdate:modelValue': () =>
                        selection.toggleAll(loaded().selectableIds),
                    'aria-label': 'Alle sichtbaren Zeilen auswählen',
                });
            };
        },
    });

    return {
        colId: SELECTION_COL_ID,
        headerComponent: HeaderRenderer,
        cellRenderer: CellRenderer,
        width: 52,
        minWidth: 52,
        maxWidth: 52,
        pinned: 'left',
        resizable: false,
        sortable: false,
        filter: false,
        editable: false,
        suppressMovable: true,
        type: 'selection',
    };
}
