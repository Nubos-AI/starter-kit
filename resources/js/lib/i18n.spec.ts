import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h, nextTick, ref } from 'vue';
import { useI18n } from '@/composables/useI18n';
import { translate, translationCatalogueKey } from '@/lib/i18n';
import type { TranslationCatalogue } from '@/lib/i18n';

describe('shared translations', () => {
    it('resolves nested core and package keys without exposing inherited properties', () => {
        const messages = {
            i18n: { save: 'Speichern' },
            'demo::i18n': { save: 'Save package' },
        };
        expect(translate(messages, 'i18n.save')).toBe('Speichern');
        expect(translate(messages, 'demo::i18n.save')).toBe('Save package');
        expect(translate(messages, 'i18n.missing')).toBe('i18n.missing');
        expect(translate(messages, 'i18n')).toBe('i18n');
        expect(translate(messages, 'i18n.toString')).toBe('i18n.toString');
    });

    it('substitutes named values once, including zero and literal replacement tokens', () => {
        const messages = {
            sentence: ':name has :count items; :unknown remains.',
        };
        expect(
            translate(messages, 'sentence', { name: ':count $&', count: 0 }),
        ).toBe(':count $& has 0 items; :unknown remains.');
    });

    it('renders explicitly empty parameters without null or undefined text', () => {
        expect(
            translate({ text: ':first/:second' }, 'text', {
                first: null,
                second: undefined,
            }),
        ).toBe('/');
    });

    it('updates a mounted component when the locale changes and renders values as text', async () => {
        const catalogue = ref<TranslationCatalogue>({
            locale: 'de',
            fallbackLocale: 'en',
            messages: { greeting: 'Hallo :name' },
        });
        const component = defineComponent({
            setup() {
                const { t, locale } = useI18n();

                return () =>
                    h(
                        'p',
                        { lang: locale.value },
                        t('greeting', { name: '<img src=x>' }),
                    );
            },
        });
        const wrapper = mount(component, {
            global: {
                provide: {
                    [translationCatalogueKey as symbol]: () => catalogue.value,
                },
            },
        });
        expect(wrapper.text()).toBe('Hallo <img src=x>');
        expect(wrapper.find('img').exists()).toBe(false);
        catalogue.value = {
            locale: 'en',
            fallbackLocale: 'en',
            messages: { greeting: 'Hello :name' },
        };
        await nextTick();
        expect(wrapper.text()).toBe('Hello <img src=x>');
        expect(wrapper.attributes('lang')).toBe('en');
    });
});
