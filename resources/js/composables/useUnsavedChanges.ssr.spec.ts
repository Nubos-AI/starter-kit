// @vitest-environment node

import { describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: () => () => undefined,
        visit: vi.fn(),
    },
}));

describe('useUnsavedChanges on the server', () => {
    it('sets up without touching window', () => {
        expect(typeof window).toBe('undefined');

        const scope = effectScope();

        expect(() =>
            scope.run(() =>
                useUnsavedChanges({
                    values: () => ({ name: 'Acme' }),
                    backHref: '/records/companies',
                }),
            ),
        ).not.toThrow();

        expect(() => scope.stop()).not.toThrow();
    });
});
