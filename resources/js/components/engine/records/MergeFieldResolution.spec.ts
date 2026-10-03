import { mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MergeFieldResolution from '@/components/engine/records/MergeFieldResolution.vue';
import type { MergeFieldPlan } from '@/types/merge';

function field(overrides: Partial<MergeFieldPlan> = {}): MergeFieldPlan {
    return {
        key: 'title',
        label: 'Titel',
        field_type: 'text_short',
        strategy: 'prefer_non_empty',
        target_value: 'Ziel',
        source_value: 'Quelle',
        result_value: 'Ziel',
        origin: 'target',
        is_conflict: true,
        requires_decision: false,
        is_overridden: false,
        ...overrides,
    };
}

function mountResolution(
    fields: MergeFieldPlan[],
    overrides: Record<string, 'target' | 'source'> = {},
): VueWrapper {
    return mount(MergeFieldResolution, {
        props: { fields, overrides },
    });
}

describe('MergeFieldResolution', () => {
    it('puts the fields that need a decision above the conflicts and the quiet rest', () => {
        const wrapper = mountResolution([
            field({ key: 'quiet', is_conflict: false }),
            field({ key: 'conflict' }),
            field({ key: 'open', requires_decision: true }),
        ]);

        expect(
            wrapper
                .findAll('[data-merge-field]')
                .map((row) => row.attributes('data-merge-field')),
        ).toEqual(['open', 'conflict', 'quiet']);
    });

    it('shows an empty value as a dash instead of nothing', () => {
        const wrapper = mountResolution([
            field({ key: 'empty', target_value: null, source_value: '' }),
        ]);

        expect(wrapper.get('[data-merge-choose-target="empty"]').text()).toBe(
            '—',
        );
        expect(wrapper.get('[data-merge-choose-source="empty"]').text()).toBe(
            '—',
        );
    });

    it('joins a list value for reading', () => {
        const wrapper = mountResolution([
            field({ key: 'tags', result_value: ['a', 'b'] }),
        ]);

        expect(wrapper.get('[data-merge-result="tags"]').text()).toBe('a, b');
    });

    it('asks for the chosen side when a value is clicked', async () => {
        const wrapper = mountResolution([field()]);

        await wrapper
            .get('[data-merge-choose-source="title"]')
            .trigger('click');

        expect(wrapper.emitted('choose')).toEqual([['title', 'source']]);
    });

    it('takes the choice back when the same side is clicked again', async () => {
        const wrapper = mountResolution([field()], { title: 'source' });

        await wrapper
            .get('[data-merge-choose-source="title"]')
            .trigger('click');

        expect(wrapper.emitted('reset')).toEqual([['title']]);
        expect(wrapper.emitted('choose')).toBeUndefined();
    });

    it('marks the decision, the conflict and the manual choice', () => {
        const open = mountResolution([
            field({ requires_decision: true, is_overridden: true }),
        ]);

        expect(open.find('[data-merge-decision-badge]').exists()).toBe(true);
        expect(open.find('[data-merge-conflict-badge]').exists()).toBe(false);
        expect(open.find('[data-merge-override-badge]').exists()).toBe(true);

        const quiet = mountResolution([
            field({ is_conflict: false, is_overridden: false }),
        ]);

        expect(quiet.find('[data-merge-conflict-badge]').exists()).toBe(false);
        expect(quiet.find('[data-merge-override-badge]').exists()).toBe(false);
    });

    it('locks the choice while the preview is being fetched', () => {
        const wrapper = mount(MergeFieldResolution, {
            props: { fields: [field()], overrides: {}, disabled: true },
        });

        expect(
            wrapper
                .get('[data-merge-choose-target="title"]')
                .attributes('disabled'),
        ).toBeDefined();
    });
});
