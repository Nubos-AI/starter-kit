import type { ColDef, ICellRendererParams } from 'ag-grid-community';
import type { PropType, VNodeChild } from 'vue';
import { defineComponent, h } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    PROMOTION_CONFLICT_STATE,
    PROMOTION_DIFF_STATE,
} from '@/lib/statusMaps';
import type {
    ConflictResolutionValue,
    PromotionDiffRow,
} from '@/types/promotion';
import type { SelectOption } from '@/types/ui';

export interface PromotionReviewState {
    isChecked: (row: PromotionDiffRow) => boolean;
    setChecked: (row: PromotionDiffRow, checked: boolean) => void;
    isOverwritten: (row: PromotionDiffRow) => boolean;
    setOverwritten: (row: PromotionDiffRow, overwritten: boolean) => void;
    decisionOf: (row: PromotionDiffRow) => ConflictResolutionValue | undefined;
    decide: (row: PromotionDiffRow, decision: ConflictResolutionValue) => void;
    decisionOptions: () => SelectOption[];
    canEdit: () => boolean;
}

function isConflictResolution(
    value: unknown,
): value is ConflictResolutionValue {
    return value === 'take_source' || value === 'keep_target';
}

function rowCell(name: string, render: (row: PromotionDiffRow) => VNodeChild) {
    return defineComponent({
        name,
        props: {
            params: {
                type: Object as PropType<ICellRendererParams<PromotionDiffRow>>,
                required: true,
            },
        },
        setup(cellProps) {
            return () => {
                const row = cellProps.params.data;

                return row === undefined ? null : render(row);
            };
        },
    });
}

const emptyCell = (): VNodeChild =>
    h('span', { class: 'text-muted-foreground' }, '—');

export function promotionDiffColumns(
    state: PromotionReviewState,
): ColDef<PromotionDiffRow>[] {
    const SelectCell = rowCell('PromotionSelectCell', (row) =>
        h(
            'span',
            {
                class: 'flex h-full items-center',
                title: row.can_select
                    ? undefined
                    : (row.select_reason ?? undefined),
            },
            h(Checkbox, {
                modelValue: state.isChecked(row),
                disabled: !row.can_select,
                'aria-label': row.can_select
                    ? `„${row.key}“ übernehmen`
                    : `„${row.key}“ übernehmen – ${row.select_reason ?? ''}`,
                'data-promotion-select': '',
                'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
                    state.setChecked(row, value === true),
            }),
        ),
    );

    const OverwriteCell = rowCell('PromotionOverwriteCell', (row) => {
        if (row.overwrite_consequence === null) {
            return emptyCell();
        }

        return h(
            'span',
            { class: 'flex h-full items-center' },
            h(Checkbox, {
                modelValue: state.isOverwritten(row),
                disabled: !row.can_overwrite || !state.canEdit(),
                'aria-label': `Gelöschten Eintrag für „${row.key}“ überschreiben`,
                'data-promotion-overwrite': '',
                'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
                    state.setOverwritten(row, value === true),
            }),
        );
    });

    const StateCell = rowCell('PromotionStateCell', (row) =>
        h('span', { class: 'flex h-full items-center gap-1' }, [
            h(StatusBadge, { map: PROMOTION_DIFF_STATE, status: row.state }),
            row.state === 'conflicted'
                ? h(StatusBadge, {
                      map: PROMOTION_CONFLICT_STATE,
                      status:
                          state.decisionOf(row) === undefined
                              ? 'undecided'
                              : 'decided',
                  })
                : null,
        ]),
    );

    const ConflictCell = rowCell('PromotionConflictCell', (row) => {
        if (row.state !== 'conflicted') {
            return emptyCell();
        }

        const disabled = !state.canEdit();

        return h(
            'span',
            {
                class: 'flex h-full items-center',
                title: disabled ? (row.select_reason ?? undefined) : undefined,
            },
            h(
                Select,
                {
                    modelValue: state.decisionOf(row),
                    disabled,
                    'onUpdate:modelValue': (value: unknown) => {
                        if (isConflictResolution(value)) {
                            state.decide(row, value);
                        }
                    },
                },
                {
                    default: () => [
                        h(
                            SelectTrigger,
                            {
                                size: 'sm',
                                disabled: disabled || undefined,
                                'aria-label': `Konflikt bei „${row.key}“ entscheiden`,
                                'data-promotion-decision': '',
                            },
                            {
                                default: () =>
                                    h(SelectValue, {
                                        placeholder: 'Bitte entscheiden',
                                    }),
                            },
                        ),
                        h(SelectContent, null, {
                            default: () =>
                                state.decisionOptions().map((option) =>
                                    h(
                                        SelectItem,
                                        {
                                            key: option.value,
                                            value: option.value,
                                        },
                                        { default: () => option.label },
                                    ),
                                ),
                        }),
                    ],
                },
            ),
        );
    });

    const HintCell = rowCell('PromotionHintCell', (row) => {
        if (row.overwrite_consequence !== null && state.isOverwritten(row)) {
            return h(
                'span',
                {
                    class: 'text-sm whitespace-normal text-warning',
                    'data-promotion-overwrite-consequence': '',
                },
                row.overwrite_consequence,
            );
        }

        if (row.refusal_reason !== null) {
            return h(
                'span',
                {
                    class: 'text-sm whitespace-normal text-danger',
                    'data-promotion-refusal': '',
                },
                row.refusal_reason,
            );
        }

        if (row.pulled_in_reason !== null) {
            return h(
                'span',
                {
                    class: 'text-sm whitespace-normal text-subtle',
                    'data-promotion-pulled-in': '',
                },
                row.pulled_in_reason,
            );
        }

        return emptyCell();
    });

    return [
        {
            colId: 'select',
            headerName: 'Übernehmen',
            width: 130,
            sortable: false,
            cellRenderer: SelectCell,
        },
        {
            colId: 'overwrite',
            headerName: 'Überschreiben',
            width: 140,
            sortable: false,
            cellRenderer: OverwriteCell,
        },
        {
            colId: 'key',
            field: 'key',
            headerName: 'Schlüssel',
            flex: 1,
            minWidth: 180,
        },
        {
            colId: 'state',
            field: 'state',
            headerName: 'Zustand',
            width: 240,
            cellRenderer: StateCell,
        },
        {
            colId: 'changed_paths',
            headerName: 'Geänderte Felder',
            flex: 1,
            minWidth: 180,
            valueGetter: (params) =>
                params.data?.changed_paths.join(', ') ?? '',
        },
        {
            colId: 'decision',
            headerName: 'Konflikt',
            width: 220,
            sortable: false,
            cellRenderer: ConflictCell,
        },
        {
            colId: 'hint',
            headerName: 'Hinweis',
            flex: 2,
            minWidth: 260,
            sortable: false,
            wrapText: true,
            autoHeight: true,
            cellRenderer: HintCell,
        },
    ];
}
