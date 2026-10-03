import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import type { ICellRendererParams } from 'ag-grid-community';
import { describe, expect, it } from 'vitest';
import AgingBadge from '@/components/engine/AgingBadge.vue';
import AgingCellRenderer from '@/components/engine/AgingCellRenderer.vue';
import { Badge } from '@/components/ui/badge';
import { AGING_THRESHOLD_COLOR, resolveStatus } from '@/lib/statusMaps';
import type { RecordAging, RecordPayload } from '@/types/records';

const RULE_NAME = 'Liegengeblieben';

function badgeAging(overrides: Partial<RecordAging> = {}): RecordAging {
    return {
        age: 58.7,
        stage: 2,
        color: 'red',
        ruleName: null,
        ...overrides,
    };
}

function mountBadge(aging: RecordAging | null): VueWrapper {
    return mount(AgingBadge, { props: { aging } });
}

function badgeVariant(wrapper: VueWrapper): unknown {
    return wrapper.findComponent(Badge).props('variant');
}

function cellParams(
    data: RecordPayload | undefined,
): ICellRendererParams<RecordPayload> {
    return { data } as unknown as ICellRendererParams<RecordPayload>;
}

function mountCell(data: RecordPayload | undefined): VueWrapper {
    return mount(AgingCellRenderer, { props: { params: cellParams(data) } });
}

function cellRecord(aging: RecordAging | null): RecordPayload {
    const record = {
        id: '01RECORD00000000000000001',
        objectTypeId: '01OBJECTTYPE000000000001',
        stageId: null,
        ownerId: null,
        recordNumber: 'REC-1',
        title: 'Angebot A',
        externalReferenceId: null,
        version: 1,
        data: {},
        createdAt: null,
        updatedAt: null,
    };

    return (aging === null
        ? record
        : { ...record, aging }) as unknown as RecordPayload;
}

describe('AgingBadge — the colour comes from the shared threshold map', () => {
    it('resolves the amber threshold through the shared map instead of its own table', () => {
        const wrapper = mountBadge(badgeAging({ color: 'amber' }));

        expect(
            wrapper.get('[data-aging-badge]').attributes('data-aging-color'),
        ).toBe('amber');
        expect(badgeVariant(wrapper)).toBe(AGING_THRESHOLD_COLOR.amber.variant);
    });

    it('resolves the red threshold to a different variant than the amber one', () => {
        const wrapper = mountBadge(badgeAging({ color: 'red' }));

        expect(
            wrapper.get('[data-aging-badge]').attributes('data-aging-color'),
        ).toBe('red');
        expect(badgeVariant(wrapper)).toBe(AGING_THRESHOLD_COLOR.red.variant);
        expect(AGING_THRESHOLD_COLOR.red.variant).not.toBe(
            AGING_THRESHOLD_COLOR.amber.variant,
        );
    });

    it('renders a threshold colour it does not know without breaking the badge', () => {
        const wrapper = mountBadge(badgeAging({ color: 'purple', age: 58.7 }));

        expect(wrapper.find('[data-aging-badge]').exists()).toBe(true);
        expect(
            wrapper.get('[data-aging-badge]').attributes('data-aging-color'),
        ).toBe('purple');
        expect(badgeVariant(wrapper)).toBe(
            resolveStatus(AGING_THRESHOLD_COLOR, 'purple').variant,
        );
        expect(wrapper.get('[data-aging-badge]').text()).toBe('58 Tage');
    });
});

describe('AgingBadge — the wording carries the age in days', () => {
    it('names an age below a day without inventing a whole day', () => {
        expect(
            mountBadge(badgeAging({ age: 0.4 }))
                .get('[data-aging-badge]')
                .text(),
        ).toBe('< 1 Tag');
    });

    it('names exactly one day in the singular', () => {
        expect(
            mountBadge(badgeAging({ age: 1 }))
                .get('[data-aging-badge]')
                .text(),
        ).toBe('1 Tag');
    });

    it('keeps the singular while the second day has not passed', () => {
        expect(
            mountBadge(badgeAging({ age: 1.9 }))
                .get('[data-aging-badge]')
                .text(),
        ).toBe('1 Tag');
    });

    it('floors a fractional age instead of rounding it up', () => {
        expect(
            mountBadge(badgeAging({ age: 58.7 }))
                .get('[data-aging-badge]')
                .text(),
        ).toBe('58 Tage');
    });

    it('carries the stage label in the title and adds the rule name when one won', () => {
        const plain = mountBadge(badgeAging({ color: 'amber' }));

        expect(plain.get('[data-aging-badge]').attributes('title')).toBe(
            AGING_THRESHOLD_COLOR.amber.label,
        );

        const named = mountBadge(
            badgeAging({ color: 'amber', ruleName: RULE_NAME }),
        );

        expect(named.get('[data-aging-badge]').attributes('title')).toBe(
            `${AGING_THRESHOLD_COLOR.amber.label} · ${RULE_NAME}`,
        );
    });
});

describe('AgingBadge — nothing at all without a reached threshold', () => {
    it('renders nothing for a record that carries no aging', () => {
        const wrapper = mountBadge(null);

        expect(wrapper.find('[data-aging-badge]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
    });

    it('renders nothing while the age reached no threshold at all', () => {
        const wrapper = mountBadge(
            badgeAging({ age: 3.2, stage: null, color: null }),
        );

        expect(wrapper.find('[data-aging-badge]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
    });
});

describe('AgingCellRenderer — the grid cell shows the badge or stays empty', () => {
    it('renders the badge of the row it was handed', () => {
        const wrapper = mountCell(
            cellRecord(badgeAging({ color: 'amber', age: 12.5 })),
        );

        expect(wrapper.find('[data-aging-badge]').exists()).toBe(true);
        expect(wrapper.get('[data-aging-badge]').text()).toBe('12 Tage');
    });

    it('leaves the cell literally empty for a row without aging, without a dash placeholder', () => {
        const wrapper = mountCell(cellRecord(null));

        expect(wrapper.find('[data-aging-badge]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
        expect(wrapper.text()).not.toContain('—');
    });

    it('survives a cell that carries no row data at all', () => {
        const wrapper = mountCell(undefined);

        expect(wrapper.find('[data-aging-badge]').exists()).toBe(false);
        expect(wrapper.text()).toBe('');
        expect(wrapper.text()).not.toContain('—');
    });
});
