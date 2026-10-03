import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';
import SegmentForm from '@/pages/segments/Form.vue';
import { selectStubs } from '@/tests/selectStubs';

vi.mock('@inertiajs/vue3', () => {
    const state = reactive<Record<string, unknown>>({
        errors: {},
        processing: false,
        transform: () => state,
        put: vi.fn(),
        post: vi.fn(),
    });

    return {
        usePage: () => ({ url: '/', props: {} }),
        Head: { template: '<head-stub><slot /></head-stub>' },
        useForm: (initial: Record<string, unknown>) =>
            Object.assign(state, initial),
        router: { on: vi.fn(() => vi.fn()), visit: vi.fn() },
    };
});

const stubs = {
    ...selectStubs,
    FilterBuilder: { template: '<div class="filter-builder-stub" />' },
};

function mountForm() {
    return mount(SegmentForm, {
        props: {
            mode: 'edit',
            segment: {
                id: 'seg-1',
                name: 'Open deals',
                object_type_id: 'obj-1',
                filter_definition: null,
            },
            objectTypeOptions: [{ value: 'obj-1', label: 'Companies' }],
            fieldsByType: {},
        },
        global: { stubs },
    });
}

describe('segments/Form', () => {
    it('keeps saving disabled until a field changes', async () => {
        const wrapper = mountForm();

        expect(wrapper.get('[data-form-save]').attributes('disabled')).toBe('');

        await wrapper.get('#segment-name').setValue('Won deals');

        expect(
            wrapper.get('[data-form-save]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('asks before leaving with pending changes', async () => {
        const wrapper = mountForm();

        await wrapper.get('#segment-name').setValue('Won deals');
        await wrapper.get('[data-form-cancel]').trigger('click');
        await nextTick();

        expect(document.body.textContent).toContain('Änderungen verwerfen?');
    });
});
