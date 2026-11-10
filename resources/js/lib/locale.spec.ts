import { afterEach, describe, expect, it, vi } from 'vitest';
import { activeLocale } from '@/lib/locale';

afterEach(() => {
    document.documentElement.lang = '';
});

describe('activeLocale', () => {
    it('follows the language the server rendered the document in', () => {
        document.documentElement.lang = 'en';

        expect(activeLocale()).toBe('en');
    });

    it('falls back to German while the document names no language', () => {
        expect(activeLocale()).toBe('de');
    });

    it('answers German during server rendering, where no document exists', () => {
        vi.stubGlobal('document', undefined);

        try {
            expect(activeLocale()).toBe('de');
        } finally {
            vi.unstubAllGlobals();
        }
    });
});
