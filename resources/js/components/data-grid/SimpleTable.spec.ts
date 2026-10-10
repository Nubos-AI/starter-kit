import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import SimpleTable from '@/components/data-grid/SimpleTable.vue';
import { tableDensityParams } from '@/lib/tableAppearance';
import type { GridDensity } from '@/types/preferences';

const settings = ref<{ density: GridDensity }>({ density: 'compact' });

vi.mock('@/composables/useUserPreferences', () => ({
    useUserPreferences: () => ({ settings }),
}));

describe('SimpleTable', () => {
    it('follows the shared density when the user changes it', async () => {
        const wrapper = mount(SimpleTable, {
            attrs: { 'aria-label': 'Zuordnung' },
            slots: { default: '<tbody><tr><td>Feld</td></tr></tbody>' },
        });

        expect(wrapper.element.tagName).toBe('TABLE');
        expect(wrapper.attributes('aria-label')).toBe('Zuordnung');
        expect(wrapper.find('td').text()).toBe('Feld');

        for (const density of ['compact', 'comfortable'] as const) {
            settings.value = { density };
            await nextTick();

            expect(
                wrapper.element.style.getPropertyValue('--table-row-height'),
            ).toBe(`${tableDensityParams[density].rowHeight}px`);
            expect(
                wrapper.element.style.getPropertyValue('--table-font-size'),
            ).toBe(`${tableDensityParams[density].fontSize}px`);
        }
    });
});
