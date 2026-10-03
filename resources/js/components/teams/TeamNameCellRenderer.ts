import { Link } from '@inertiajs/vue3';
import type { ICellRendererParams } from 'ag-grid-community';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import type { TeamTreeNode } from '@/types/teams';

type TeamNameCellParams = ICellRendererParams<TeamTreeNode> & {
    href?: (row: TeamTreeNode) => string | undefined;
};

const indentStep = 1.25;

export const TeamNameCellRenderer = defineComponent({
    name: 'TeamNameCellRenderer',
    props: {
        params: {
            type: Object as PropType<TeamNameCellParams>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const row = cellProps.params.data;

            if (row === undefined || row === null) {
                return h('span', { class: 'text-muted-foreground' }, '—');
            }

            const href = cellProps.params.href?.(row);

            const label =
                href === undefined
                    ? h('span', { class: 'font-medium' }, row.name)
                    : h(
                          Link,
                          {
                              href,
                              'data-team-name-link': row.id,
                              class: 'font-medium text-primary underline-offset-4 hover:underline',
                          },
                          () => row.name,
                      );

            return h(
                'span',
                {
                    'data-team-name': row.id,
                    'data-team-depth': String(row.depth),
                    class: 'flex items-center',
                    style: { paddingLeft: `${row.depth * indentStep}rem` },
                },
                [label],
            );
        };
    },
});
