import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ListPager from '@/components/data-grid/ListPager.vue';

type Wrapper = ReturnType<typeof mount>;

function mountPager(
    currentPage: number,
    lastPage: number,
    isDisabled = false,
): Wrapper {
    return mount(ListPager, { props: { currentPage, lastPage, isDisabled } });
}

describe('ListPager', () => {
    it('renders nothing while a single page holds every row', () => {
        expect(
            mountPager(1, 1).find('[data-testid="list-pager"]').exists(),
        ).toBe(false);
    });

    it('reports the neighbouring pages it is asked to change to', async () => {
        const wrapper = mountPager(2, 3);

        await wrapper.get('[data-testid="list-pager-next"]').trigger('click');
        await wrapper
            .get('[data-testid="list-pager-previous"]')
            .trigger('click');

        expect(wrapper.emitted('change-page')).toEqual([[3], [1]]);
        expect(wrapper.text()).toContain('Seite 2 von 3');
    });

    it('blocks both steps while the parent is still loading a page', () => {
        const wrapper = mountPager(2, 3, true);

        expect(
            wrapper
                .get('[data-testid="list-pager-next"]')
                .attributes('disabled'),
        ).toBeDefined();
        expect(
            wrapper
                .get('[data-testid="list-pager-previous"]')
                .attributes('disabled'),
        ).toBeDefined();
    });
});
