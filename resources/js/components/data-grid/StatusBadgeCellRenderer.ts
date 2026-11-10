import type { ColDef, ICellRendererParams } from 'ag-grid-community';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import { StatusBadge } from '@/components/ui/status-badge';
import type { StatusMap } from '@/lib/statusMaps';

type StatusBadgeCellParams = ICellRendererParams & {
    statusMap?: StatusMap;
};

export const StatusBadgeCellRenderer = defineComponent({
    name: 'StatusBadgeCellRenderer',
    props: {
        params: {
            type: Object as PropType<StatusBadgeCellParams>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const value = cellProps.params.value;
            const map = cellProps.params.statusMap;

            if (value === null || value === undefined || map === undefined) {
                return h('span', { class: 'text-muted-foreground' }, '—');
            }

            return h(StatusBadge, { map, status: String(value) });
        };
    },
});

export function statusBadgeColumn<TRow>(
    field: string,
    map: StatusMap,
    options?: Partial<ColDef<TRow>>,
): ColDef<TRow> {
    return {
        colId: field,
        field: field as ColDef<TRow>['field'],
        cellRenderer: StatusBadgeCellRenderer,
        cellRendererParams: { statusMap: map },
        ...options,
    };
}
