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

    it('leaves a root record flush with the column edge', () => {
        const wrapper = mount(BusinessKeyCell, {
            props: { record: { ...record(), hierarchyDepth: 0 } },
        });

        expect(wrapper.find('[data-hierarchy-branch]').exists()).toBe(false);
        expect(
            wrapper.get('[data-business-key-cell]').attributes('style') ?? '',
        ).not.toContain('padding-left');
    });

    it('puts the branch of a first-level child flush with its parent', () => {
        const wrapper = mount(BusinessKeyCell, {
            props: { record: { ...record(), hierarchyDepth: 1 } },
        });

        expect(
            wrapper.get('[data-business-key-cell]').attributes('style') ?? '',
        ).not.toContain('padding-left');
        expect(wrapper.find('[data-hierarchy-branch]').exists()).toBe(true);
    });

    it('indents every further level by exactly one branch width', () => {
        const wrapper = mount(BusinessKeyCell, {
            props: { record: { ...record(), hierarchyDepth: 3 } },
        });

        expect(
            wrapper.get('[data-business-key-cell]').attributes('style'),
        ).toContain('padding-left: 2rem');
    });

    it('renders plain text while the row carries no record', () => {
        const wrapper = mount(BusinessKeyCell, { props: { record: null } });

        expect(wrapper.find('[data-testid="business-key-link"]').exists()).toBe(
            false,
        );
        expect(wrapper.text()).toBe('—');
    });
});
