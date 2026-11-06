import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import BusinessKeyCell from '@/components/engine/BusinessKeyCell.vue';
import type { RecordPayload } from '@/types/records';

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: ['href'],
        template: '<a :href="href"><slot /></a>',
    },
}));

function record(): RecordPayload {
    return {
        id: '01kzjwb4671y42r6cffx8g14qp',
        recordNumber: 'CO-0000000000',
        version: 1,
        data: {},
    } as RecordPayload;
}

describe('BusinessKeyCell', () => {
    it('links the business key to the record page', () => {
        const wrapper = mount(BusinessKeyCell, {
            props: { record: record() },
        });
        const link = wrapper.get('[data-testid="business-key-link"]');

        expect(link.text()).toBe('CO-0000000000');
        expect(link.attributes('href')).toContain('CO-0000000000');
    });

    it('falls the link back to the ulid without a business key', () => {
        const wrapper = mount(BusinessKeyCell, {
            props: { record: { ...record(), recordNumber: null } },
        });
        const link = wrapper.get('[data-testid="business-key-link"]');

        expect(link.text()).toBe('—');
        expect(link.attributes('href')).toContain('01kzjwb4671y42r6cffx8g14qp');
    });

    it.each<[string, number, boolean, string | null]>([
        ['leaves a root record flush with the column edge', 0, false, null],
        [
            'puts the branch of a first-level child flush with its parent',
            1,
            true,
            null,
        ],
        [
            'indents every further level by exactly one branch width',
            3,
            true,
            'padding-left: 2rem',
        ],
    ])('%s', (_case, hierarchyDepth, hasBranch, indentation) => {
        const wrapper = mount(BusinessKeyCell, {
            props: { record: { ...record(), hierarchyDepth } },
        });
        const style =
            wrapper.get('[data-business-key-cell]').attributes('style') ?? '';

        expect(wrapper.find('[data-hierarchy-branch]').exists()).toBe(
            hasBranch,
        );

        if (indentation === null) {
            expect(style).not.toContain('padding-left');
        } else {
            expect(style).toContain(indentation);
        }
    });

    it('renders plain text while the row carries no record', () => {
        const wrapper = mount(BusinessKeyCell, { props: { record: null } });

        expect(wrapper.find('[data-testid="business-key-link"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toBe('—');
    });
});

it('shows the readable title together with its stable record number', () => {
    const wrapper = mount(BusinessKeyCell, {
        props: { record: { ...record(), title: 'Römer GmbH' } },
    });
    const link = wrapper.get('[data-testid="business-key-link"]');
    expect(link.text()).toContain('Römer GmbH');
    expect(link.text()).toContain('CO-0000000000');
    expect(link.attributes('href')).toContain('CO-0000000000');
});
