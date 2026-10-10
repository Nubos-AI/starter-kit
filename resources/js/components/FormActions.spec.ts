import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import FormActions from '@/components/FormActions.vue';

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

function mountActions(props: Record<string, unknown> = {}) {
    return mount(FormActions, {
        props: { dirty: false, ...props },
    });
}

function saveButton(wrapper: ReturnType<typeof mountActions>) {
    return wrapper.get('[data-form-save]');
}

describe('FormActions', () => {
    it('aligns the action row to the right', () => {
        const wrapper = mountActions();

        expect(wrapper.get('[data-form-actions]').classes()).toContain(
            'justify-end',
        );
    });

    it('puts cancel before save so the primary action sits outermost', () => {
        const wrapper = mountActions({ dirty: true });
        const order = wrapper
            .findAll('button')
            .map((button) =>
                button.attributes('data-form-cancel') === ''
                    ? 'cancel'
                    : 'save',
            );

        expect(order).toEqual(['cancel', 'save']);
    });

    it('keeps the save button disabled while nothing is dirty', () => {
        const wrapper = mountActions();

        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('enables the save button once the form is dirty', () => {
        const wrapper = mountActions({ dirty: true });

        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('keeps the save button disabled while a submit is in flight', () => {
        const wrapper = mountActions({ dirty: true, processing: true });

        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('submits the surrounding form by default', () => {
        const wrapper = mountActions({ dirty: true });

        expect(saveButton(wrapper).attributes('type')).toBe('submit');
    });

    it('emits save instead of submitting when asked to', async () => {
        const wrapper = mountActions({ dirty: true, type: 'button' });

        expect(saveButton(wrapper).attributes('type')).toBe('button');

        await saveButton(wrapper).trigger('click');

        expect(wrapper.emitted('save')).toHaveLength(1);
    });

    it('never submits through the create button, whatever it is handed', () => {
        const wrapper = mountActions({
            dirty: true,
            mode: 'create',
            saveLabel: 'Create team',
        } as Record<string, unknown>);

        expect(
            saveButton(wrapper).attributes('data-create-button'),
        ).toBeUndefined();
        expect(saveButton(wrapper).text()).toBe('Speichern');
    });

    it('drops the cancel button where there is nowhere to go back to', () => {
        const wrapper = mountActions({ dirty: true, cancellable: false });

        expect(wrapper.find('[data-form-cancel]').exists()).toBe(false);
        expect(wrapper.findAll('button')).toHaveLength(1);
        expect(saveButton(wrapper).attributes('data-form-save')).toBe('');
    });

    it('offers a cancel button that stays usable and emits', async () => {
        const wrapper = mountActions({ dirty: true, processing: true });
        const cancel = wrapper.get('[data-form-cancel]');

        expect(cancel.text()).toBe('Abbrechen');
        expect(cancel.attributes('type')).toBe('button');

        await cancel.trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
    });
});
