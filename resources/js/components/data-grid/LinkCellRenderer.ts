import type { ICellRendererParams } from 'ag-grid-community';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import TableLink from '@/components/data-grid/TableLink.vue';

type LinkCellParams<TRow> = ICellRendererParams<TRow> & {
    href?: (row: TRow) => string;
    testId?: string;
};

export const LinkCellRenderer = defineComponent({
    name: 'LinkCellRenderer',
    props: {
        params: {
            type: Object as PropType<LinkCellParams<unknown>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const row = cellProps.params.data;
            const value = cellProps.params.value;

            if (row === undefined || row === null) {
                return h('span', { class: 'text-muted-foreground' }, '—');
            }

            const href = cellProps.params.href?.(row);

            if (href === undefined) {
                return h('span', {}, String(value ?? '—'));
            }

            return h(
                TableLink,
                {
                    href,
                    'data-testid': cellProps.params.testId,
                },
                () => String(value ?? '—'),
            );
        };
    },
});
