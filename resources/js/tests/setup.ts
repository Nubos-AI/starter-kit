import { config, enableAutoUnmount } from '@vue/test-utils';
import { afterEach, vi } from 'vitest';
import { translationCatalogueKey } from '@/lib/i18n';
import type { TranslationTree } from '@/lib/i18n';

const catalogueFiles = import.meta.glob<{ default: TranslationTree }>(
    [
        '../../lang/de/i18n.json',
        '../../../packages/*/*/resources/lang/de/i18n.json',
    ],
    { eager: true },
);
const messages: TranslationTree = {};

for (const [path, catalogue] of Object.entries(catalogueFiles)) {
    const namespace = path.match(/packages\/[^/]+\/([^/]+)\//)?.[1];
    messages[namespace ? `${namespace}::i18n` : 'i18n'] = catalogue.default;
}

config.global.provide = {
    ...config.global.provide,
    [translationCatalogueKey as symbol]: () => ({
        locale: 'de',
        fallbackLocale: 'en',
        messages,
    }),
};

if (typeof window !== 'undefined' && typeof window.matchMedia !== 'function') {
    window.matchMedia = (query: string): MediaQueryList =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }) as unknown as MediaQueryList;
}

if (typeof globalThis.CSS === 'undefined') {
    globalThis.CSS = { escape: (value: string): string => value } as typeof CSS;
} else if (typeof globalThis.CSS.escape !== 'function') {
    globalThis.CSS.escape = (value: string): string => value;
}

if (typeof globalThis.ResizeObserver === 'undefined') {
    globalThis.ResizeObserver = class {
        observe(): void {}

        unobserve(): void {}

        disconnect(): void {}
    } as unknown as typeof ResizeObserver;
}

if (typeof globalThis.IntersectionObserver === 'undefined') {
    globalThis.IntersectionObserver = class {
        observe(): void {}

        unobserve(): void {}

        disconnect(): void {}

        takeRecords(): IntersectionObserverEntry[] {
            return [];
        }
    } as unknown as typeof IntersectionObserver;
}

enableAutoUnmount(afterEach);
