import type { InjectionKey } from 'vue';

export interface TranslationTree {
    [key: string]: string | TranslationTree;
}

export interface TranslationCatalogue {
    locale: string;
    fallbackLocale: string;
    messages: TranslationTree;
}

export type TranslationParameters = Record<
    string,
    string | number | null | undefined
>;

export const translationCatalogueKey: InjectionKey<() => TranslationCatalogue> =
    Symbol('translationCatalogue');

export function translate(
    catalogue: TranslationTree,
    key: string,
    parameters: TranslationParameters = {},
): string {
    let value: string | TranslationTree = catalogue;

    for (const part of key.split('.')) {
        if (typeof value === 'string' || !Object.hasOwn(value, part)) {
            return key;
        }

        value = value[part];
    }

    if (typeof value !== 'string') {
        return key;
    }

    return value.replace(/:([a-zA-Z_][a-zA-Z0-9_]*)/g, (token, name: string) =>
        Object.hasOwn(parameters, name)
            ? String(parameters[name] ?? '')
            : token,
    );
}
