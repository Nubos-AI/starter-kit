import { ChevronDown, ChevronRight } from '@lucide/vue';
import type { ICellRendererParams } from 'ag-grid-community';
import type { Component, PropType } from 'vue';
import { defineComponent, h } from 'vue';

export interface GroupHeaderRow {
    id: string;
    isGroupHeader: true;
    label: string;
    count: number;
    collapsed: boolean;
}

export function isGroupHeaderRow(row: unknown): row is GroupHeaderRow {
    return (
        typeof row === 'object' &&
        row !== null &&
        'isGroupHeader' in row &&
        row.isGroupHeader === true
    );
}

export function groupHeaderCellRenderer(
    onToggle: (groupId: string) => void,
    dataAttribute: string,
): Component {
    return defineComponent({
        name: 'GroupHeaderCellRenderer',
        props: {
            params: {
                type: Object as PropType<ICellRendererParams<unknown>>,
                required: true,
            },
        },
        setup(cellProps) {
            return () => {
                const row: unknown = cellProps.params.data;

                if (!isGroupHeaderRow(row)) {
                    return null;
                }

                return h(
                    'button',
                    {
                        type: 'button',
                        class: 'flex h-full w-full items-center gap-2 bg-muted/60 px-3 text-left text-sm font-medium',
                        [dataAttribute]: row.id,
                        'aria-expanded': !row.collapsed,
                        onClick: () => onToggle(row.id),
                    },
                    [
                        h(row.collapsed ? ChevronRight : ChevronDown, {
                            class: 'size-4 text-muted-foreground',
                        }),
                        h('span', row.label),
                        h(
                            'span',
                            {
                                class: 'text-xs font-normal text-muted-foreground',
                            },
                            String(row.count),
                        ),
                    ],
                );
            };
        },
    });
}
