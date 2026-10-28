import type { Component } from 'vue';

export interface RowAction<TRow> {
    icon: Component;
    label: string;
    variant?:
        | 'default'
        | 'destructive'
        | 'edit'
        | 'info'
        | 'success'
        | 'warning';
    testId?: string;
    onClick?: (row: TRow) => void;
    href?: (row: TRow) => string;
    download?: boolean;
    isVisible?: (row: TRow) => boolean;
    isDisabled?: (row: TRow) => boolean;
    disabledReason?: (row: TRow) => string | undefined;
}
