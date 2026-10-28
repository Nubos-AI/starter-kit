import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RecordPeopleTrigger from '@/components/engine/records/RecordPeopleTrigger.vue';

function mountTrigger(
    props: Record<string, unknown>,
): ReturnType<typeof mount> {
    return mount(RecordPeopleTrigger, {
        props: { caption: 'Besitzer', label: 'Anna Albers', ...props },
    });
}

describe('RecordPeopleTrigger', () => {
    it('shows the name above its caption', () => {
        const wrapper = mountTrigger({});

        expect(wrapper.get('[data-people-trigger-label]').text()).toBe(
            'Anna Albers',
        );
        expect(wrapper.get('[data-people-trigger-caption]').text()).toBe(
            'Besitzer',
        );
    });

    it('renders the avatar of the named person', () => {
        const wrapper = mountTrigger({ avatarName: 'Anna Albers' });

        expect(wrapper.find('[data-user-avatar]').exists()).toBe(true);
    });

    it('renders a neutral placeholder when nobody is named', () => {
        const wrapper = mountTrigger({
            label: 'Kein Besitzer',
            avatarName: null,
        });

        expect(wrapper.find('[data-user-avatar]').exists()).toBe(false);
        expect(wrapper.find('[data-people-trigger-placeholder]').exists()).toBe(
            true,
        );
    });

    it('disables the button when it is told to', () => {
        const wrapper = mountTrigger({ disabled: true });

        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
    });
});
