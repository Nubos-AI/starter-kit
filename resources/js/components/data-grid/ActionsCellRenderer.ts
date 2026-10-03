import type { ICellRendererParams } from 'ag-grid-community';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import { IconActionButton } from '@/components/ui/icon-action-button';
import type { RowAction } from '@/types/rowAction';

type ActionsCellParams<TRow> = ICellRendererParams<TRow> & {
    actions?: RowAction<TRow>[];
};

export const ActionsCellRenderer = defineComponent({
    name: 'ActionsCellRenderer',
    props: {
        params: {
            type: Object as PropType<ActionsCellParams<unknown>>,
            required: true,
        },
    },
    setup(cellProps) {
        return () => {
            const row = cellProps.params.data;
            const actions = cellProps.params.actions ?? [];

            if (row === undefined || row === null) {
                return null;
            }

            const visible = actions.filter(
                (action) => action.isVisible?.(row) ?? true,
            );

            return h(
                'div',
                { class: 'flex h-full items-center justify-end gap-1' },
                visible.map((action) => {
                    const disabled = action.isDisabled?.(row) ?? false;
                    const reason = disabled
                        ? action.disabledReason?.(row)
                        : undefined;

                    return h(IconActionButton, {
                        key: action.testId ?? action.label,
                        icon: action.icon,
                        label: action.label,
                        title: reason ?? action.label,
                        variant: action.variant,
                        href: action.href?.(row),
                        download: action.download,
                        disabled,
                        testId: action.testId,
                        onClick: () => {
                            if (disabled) {
                                return;
                            }

                            action.onClick?.(row);
                        },
                    });
                }),
            );
        };
    },
});
