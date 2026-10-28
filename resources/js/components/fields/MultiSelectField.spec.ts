import { mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import MultiSelectField from '@/components/fields/MultiSelectField.vue';
import type { FieldDefinition } from '@/types/fields';

const field = {
    key: 'interests',
    label: 'Interessen',
    field_type: 'multi_select',
    is_required: false,
    config: { options: ['Sport', 'Musik'] },
} as unknown as FieldDefinition;

beforeAll(() => {
    Element.prototype.scrollIntoView = () => undefined;
});

beforeEach(() => {
    document.body.replaceChildren();
});

function mountField(modelValue: string[] | null) {
    return mount(MultiSelectField, {
        props: { field, modelValue: modelValue as string[] },
        attachTo: document.body,
    });
}

describe('MultiSelectField', () => {
    it('renders a record whose stored value is null', () => {
        const wrapper = mountField(null);

        expect(wrapper.find('[data-multi-select-trigger]').text()).toContain(
            'Werte wählen…',
        );
    });

    it('turns a null value into a list as soon as an option is picked', async () => {
        const wrapper = mountField(null);

        await wrapper.find('[data-multi-select-trigger]').trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));

        const option = document.querySelectorAll('[data-multi-select-item]')[1];
        option.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([
            ['Musik'],
        ]);
    });

    it('shows the stored selection as tokens', () => {
        const wrapper = mountField(['Sport']);

        expect(wrapper.find('[data-multi-select-trigger]').text()).toContain(
            'Sport',
        );
    });
});
