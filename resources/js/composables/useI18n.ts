import { usePage } from '@inertiajs/vue3';
import { computed, inject } from 'vue';
import type { ComputedRef } from 'vue';
import { translate, translationCatalogueKey } from '@/lib/i18n';
import type { TranslationCatalogue, TranslationParameters } from '@/lib/i18n';

interface I18n {
    locale: ComputedRef<string>;
    t: (key: string, parameters?: TranslationParameters) => string;
}

export function useI18n(): I18n {
    const provided = inject(translationCatalogueKey, null);
    const page = provided ? null : usePage();
    const catalogue = computed<TranslationCatalogue>(() =>
        provided ? provided() : page!.props.i18n,
    );
    const locale = computed(() => catalogue.value?.locale ?? 'de');

    function t(key: string, parameters: TranslationParameters = {}): string {
        return translate(catalogue.value?.messages ?? {}, key, parameters);
    }

    return { locale, t };
}
