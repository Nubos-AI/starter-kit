import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { computed, defineComponent, h, nextTick, ref } from 'vue';
import { useModuleOptions } from '@/composables/useModuleOptions';
import { provideUiExtensions } from '@/composables/useUiExtensions';
import type { UiOptions } from '@/types/modules';

describe('useModuleOptions', () => {
    it('only exposes the contributions the server shares for the point', async () => {
        const options = ref<UiOptions>({});
        const consumer = defineComponent({
            setup() {
                const choices = useModuleOptions('example.options', [
                    { value: 'base', label: 'Base' },
                ]);

                return () =>
                    h(
                        'div',
                        choices.value.map((option) => option.label).join(', '),
                    );
            },
        });
        const wrapper = mount(
            defineComponent({
                setup() {
                    provideUiExtensions(
                        computed(() => ({
                            modules: [],
                            extensions: [],
                            options: options.value,
                            overrides: {},
                            page: {},
                        })),
                    );

                    return () => h(consumer);
                },
            }),
        );

        expect(wrapper.text()).toBe('Base');
        options.value = {
            'example.options': [{ value: 'extra', label: 'Extra' }],
            'other.options': [{ value: 'other', label: 'Other' }],
        };
        await nextTick();
        expect(wrapper.text()).toBe('Base, Extra');
        options.value = {};
        await nextTick();
        expect(wrapper.text()).toBe('Base');
        wrapper.unmount();
    });
});
