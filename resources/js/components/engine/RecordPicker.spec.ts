import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Ref } from 'vue';
import { ref } from 'vue';
import RecordPicker from '@/components/engine/RecordPicker.vue';
import { comboboxStubs } from '@/tests/selectStubs';

const { state, loadMoreMock } = vi.hoisted(() => ({
    state: {} as { search?: Ref<string> },
    loadMoreMock: vi.fn(),
}));

vi.mock('@/composables/useRecordLookup', () => ({
    useRecordLookup: () => {
        state.search ??= ref('');

        return {
            options: ref([{ value: 'rec-1', label: 'Hafenprojekt' }]),
            loading: ref(false),
            search: state.search,
            hasMore: ref(true),
            reload: vi.fn(),
            loadMore: loadMoreMock,
        };
    },
}));

function mountPicker() {
    return mount(RecordPicker, {
        props: { objectTypeSlug: 'companies', modelValue: null },
        global: { stubs: comboboxStubs },
    });
}

beforeEach(() => {
    state.search = ref('');
    loadMoreMock.mockReset().mockResolvedValue(undefined);
});

describe('RecordPicker', () => {
    it('hands the typed term to the lookup instead of filtering on its own', async () => {
        const wrapper = mountPicker();

        await wrapper.get('input.ui-combobox-search').setValue('Hafen');

        expect(state.search?.value).toBe('Hafen');
    });

    it('asks the lookup for more once the list is scrolled to its end', async () => {
        const wrapper = mountPicker();

        await wrapper.get('select.ui-combobox').trigger('scroll');

        expect(loadMoreMock).toHaveBeenCalledTimes(1);
    });
});
