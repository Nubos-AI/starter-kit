import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import ChartDownloadButton from '@/components/charts/ChartDownloadButton.vue';

describe('ChartDownloadButton', () => {
    it('asks its owner for a download when it is clicked', async () => {
        const wrapper = mount(ChartDownloadButton);

        await wrapper.find('[data-chart-download]').trigger('click');

        expect(wrapper.emitted('download')).toHaveLength(1);
    });

    it('refuses a second request while a download is pending', async () => {
        const wrapper = mount(ChartDownloadButton, {
            props: { isPending: true },
        });
        const button = wrapper.find('[data-chart-download]');

        expect(button.attributes('disabled')).toBeDefined();

        await button.trigger('click');

        expect(wrapper.emitted('download')).toBeUndefined();
    });
});
