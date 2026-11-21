import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { defineComponent, h } from 'vue';
import { useI18n } from '@/shared/composables/useI18n';
import { translationCatalogueKey } from '@/shared/lib/i18n';

type I18n = ReturnType<typeof useI18n>;

function i18nFor(locale: string): I18n {
  let captured: I18n | null = null;

  mount(
    defineComponent({
      setup() {
        captured = useI18n();

        return () => h('div');
      },
    }),
    {
      global: {
        provide: {
          [translationCatalogueKey as symbol]: () => ({
            locale,
            fallbackLocale: 'en',
            messages: {},
          }),
        },
      },
    },
  );

  if (captured === null) {
    throw new Error('useI18n was not called');
  }

  return captured;
}

describe('useI18n — n()', () => {
  it('writes a number in the language of the catalogue', () => {
    expect(i18nFor('de').n(1234.5)).toBe('1.234,5');
    expect(i18nFor('en').n(1234.5)).toBe('1,234.5');
  });

  it('passes currency and percent options through', () => {
    const { n } = i18nFor('de');

    expect(n(1234.5, { style: 'currency', currency: 'EUR' })).toBe('1.234,50 €');
    expect(n(0.5, { style: 'percent' })).toBe('50 %');
  });
});

describe('useI18n — d()', () => {
  it('writes a moment in the language of the catalogue', () => {
    const options: Intl.DateTimeFormatOptions = {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    };

    expect(i18nFor('de').d('2026-10-06T14:05:00Z', options)).toBe('6. Oktober 2026');
    expect(i18nFor('en').d(new Date('2026-10-06T14:05:00Z'), options)).toBe('October 6, 2026');
  });

  it('falls back to a dash without a moment', () => {
    expect(i18nFor('de').d(null)).toBe('—');
  });
});

describe('useI18n — scoped()', () => {
  it('translates a key below the given prefix', () => {
    const label = i18nFor('de').scoped('demo::i18n.pages.index');

    expect(label('title')).toBe('demo::i18n.pages.index.title');
  });
});
