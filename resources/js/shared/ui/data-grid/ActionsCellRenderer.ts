import type { ICellRendererParams } from 'ag-grid-community';
import type { PropType } from 'vue';
import { defineComponent, h } from 'vue';
import type { RowAction } from '@/shared/types/rowAction';
import { IconActionButton } from '@/shared/ui/primitives/icon-action-button';
import UiExtensionPoint from '@/shared/ui/UiExtensionPoint.vue';

type ActionsCellParams<TRow> = ICellRendererParams<TRow> & {
  actions?: RowAction<TRow>[];
  extensionPoint?: string;
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

      const visible = actions.filter((action) => action.isVisible?.(row) ?? true);
      const extensionPoint = cellProps.params.extensionPoint;

      const buttons = visible.map((action) => {
        const disabled = action.isDisabled?.(row) ?? false;
        const reason = disabled ? action.disabledReason?.(row) : undefined;

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
      });

      if (extensionPoint === undefined) {
        return h('div', { class: 'flex h-full items-center justify-end gap-1' }, buttons);
      }

      const deleteAt = visible.findIndex((action) => action.variant === 'destructive');
      const insertAt = deleteAt === -1 ? buttons.length : deleteAt;
      const extensions = h(UiExtensionPoint, { key: 'row-extensions', name: extensionPoint, context: { row } });

      return h('div', { class: 'flex h-full items-center justify-end gap-1' }, [
        ...buttons.slice(0, insertAt),
        extensions,
        ...buttons.slice(insertAt),
      ]);
    };
  },
});
