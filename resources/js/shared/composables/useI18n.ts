import { usePage } from '@inertiajs/vue3';
import { computed, hasInjectionContext, inject } from 'vue';
import type { ComputedRef } from 'vue';
import { formatLocalizedDate } from '@/shared/lib/formatDate';
import type { DateInput } from '@/shared/lib/formatDate';
import { scopedTranslator, translate, translationCatalogueKey } from '@/shared/lib/i18n';
import type { ScopedTranslator, TranslationCatalogue, TranslationParameters } from '@/shared/lib/i18n';
import { formatLocalizedNumber } from '@/shared/lib/localizedNumber';

interface I18n {
  locale: ComputedRef<string>;
  t: (key: string, parameters?: TranslationParameters) => string;
  n: (value: unknown, options?: Intl.NumberFormatOptions) => string;
  d: (value: DateInput, options?: Intl.DateTimeFormatOptions) => string;
  scoped: (prefix: string) => ScopedTranslator;
}

export function useI18n(): I18n {
  const provided = hasInjectionContext() ? inject(translationCatalogueKey, null) : null;
  const page = provided ? null : usePage();
  const catalogue = computed<TranslationCatalogue>(() => (provided ? provided() : page!.props.i18n));
  const locale = computed(() => catalogue.value?.locale ?? 'de');

  function t(key: string, parameters: TranslationParameters = {}): string {
    return translate(catalogue.value?.messages ?? {}, key, parameters);
  }

  function n(value: unknown, options: Intl.NumberFormatOptions = {}): string {
    return formatLocalizedNumber(value, options, locale.value);
  }

  function d(value: DateInput, options: Intl.DateTimeFormatOptions = {}): string {
    return formatLocalizedDate(value, options, locale.value);
  }

  function scoped(prefix: string): ScopedTranslator {
    return scopedTranslator(t, prefix);
  }

  return { locale, t, n, d, scoped };
}
