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
    it.each([
        ['the avatar of the named person', { avatarName: 'Anna Albers' }, true],
        [
            'a neutral placeholder when nobody is named',
            { label: 'Kein Besitzer', avatarName: null },
            false,
        ],
    ])('renders %s', (_case, props, hasAvatar) => {
        const wrapper = mountTrigger(props);

        expect(wrapper.find('[data-user-avatar]').exists()).toBe(hasAvatar);
        expect(wrapper.find('[data-people-trigger-placeholder]').exists()).toBe(
            !hasAvatar,
        );
    });
});
