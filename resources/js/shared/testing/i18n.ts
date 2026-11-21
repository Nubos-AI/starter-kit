import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import { scopedTranslator, translate } from '@/shared/lib/i18n';
import type { ScopedTranslator, TranslationParameters, TranslationTree } from '@/shared/lib/i18n';

const coreCatalogue = import.meta.glob<{ default: TranslationTree }>('/resources/lang/de/i18n.json', { eager: true });
const moduleCatalogues = import.meta.glob<{ default: TranslationTree }>('/vendor/nubos/*/resources/lang/de/i18n.json', {
  eager: true,
});

export const catalogueMessages: TranslationTree = {};

for (const catalogue of Object.values(coreCatalogue)) {
  catalogueMessages.i18n = catalogue.default;
}

for (const [path, catalogue] of Object.entries(moduleCatalogues)) {
  catalogueMessages[`${path.split('/')[3]}::i18n`] = catalogue.default;
}

export function t(key: string, parameters: TranslationParameters = {}): string {
  return translate(catalogueMessages, key, parameters);
}

export function scoped(prefix: string): ScopedTranslator {
  return scopedTranslator(t, prefix);
}

export function useI18n(): {
  locale: ComputedRef<string>;
  t: (key: string, parameters?: TranslationParameters) => string;
  scoped: (prefix: string) => ScopedTranslator;
} {
  return { locale: computed(() => 'de'), t, scoped };
}
