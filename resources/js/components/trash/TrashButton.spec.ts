import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import TrashButton from '@/components/trash/TrashButton.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

describe('TrashButton — the trash sits next to the notification bell', () => {
    it('names itself for assistive technology', () => {
        const wrapper = mount(TrashButton);

        expect(
            wrapper.find('[data-trash-button]').attributes('aria-label'),
        ).toBe('Papierkorb');
    });
});
