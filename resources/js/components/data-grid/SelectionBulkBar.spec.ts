import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SelectionBulkBar from '@/components/data-grid/SelectionBulkBar.vue';

type Wrapper = ReturnType<typeof mount>;

function mountBar(count = 2, deleteLabel?: string): Wrapper {
    return mount(SelectionBulkBar, {
        props: { count, deleteLabel },
    });
}

function bulkDelete(wrapper: Wrapper) {
    return wrapper.get('[data-testid="bulk-delete"]');
}

function confirmButton(wrapper: Wrapper) {
    return wrapper.get('[data-testid="bulk-delete-confirm"]');
}

describe('SelectionBulkBar', () => {
    it('shows how many rows are selected', () => {
        expect(mountBar(3).text()).toContain('3 ausgewählt');
    });

    it('asks before deleting instead of emitting straight away', async () => {
        const wrapper = mountBar();

        await bulkDelete(wrapper).trigger('click');

        expect(wrapper.emitted('delete')).toBeUndefined();
        expect(
            wrapper.find('[data-testid="bulk-delete-confirm"]').exists(),
        ).toBe(true);
    });

    it('emits delete once the deletion is confirmed', async () => {
        const wrapper = mountBar();

        await bulkDelete(wrapper).trigger('click');
        await confirmButton(wrapper).trigger('click');

        expect(wrapper.emitted('delete')).toHaveLength(1);
    });

    it('names the number of rows in the question', async () => {
        const wrapper = mountBar(4);

        await bulkDelete(wrapper).trigger('click');

        expect(wrapper.text()).toContain('4');
    });

    it('emits clear when the selection is dropped', async () => {
        const wrapper = mountBar();

        await wrapper.get('[data-testid="bulk-clear"]').trigger('click');

        expect(wrapper.emitted('clear')).toHaveLength(1);
    });
});
