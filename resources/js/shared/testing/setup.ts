import { cleanup } from '@testing-library/vue';
import { config, enableAutoUnmount } from '@vue/test-utils';
import { afterAll, afterEach, vi } from 'vitest';
import { translationCatalogueKey } from '@/shared/lib/i18n';
import { catalogueMessages } from './i18n';
import { installMswLifecycle } from './msw';
import { drainShortTimers, trackShortTimers } from './pendingTimers';

config.global.provide = {
  ...config.global.provide,
  [translationCatalogueKey as symbol]: () => ({
    locale: 'de',
    fallbackLocale: 'en',
    messages: catalogueMessages,
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

trackShortTimers();

enableAutoUnmount(afterEach);

installMswLifecycle();

afterEach(cleanup);

afterAll(drainShortTimers);
