import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ReminderTypeForm from '@/pages/reminderTypes/Form.vue';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ url: '/', props: {} }),
    Head: { template: '<head-stub><slot /></head-stub>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Form: {
        name: 'InertiaFormStub',
        props: ['transform', 'onSuccess'],
        template: '<form><slot :errors="{}" :processing="false" /></form>',
    },
    router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
}));

function mountForm() {
    return mount(ReminderTypeForm, {
        props: {
            mode: 'edit',
            reminderType: { id: 'type-1', name: 'Call' },
        },
    });
}

describe('reminderTypes/Form', () => {
    it('keeps saving disabled until something changes', async () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#reminder-type-name').setValue('Follow-up');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm();

        await wrapper.get('#reminder-type-name').setValue('Follow-up');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});
