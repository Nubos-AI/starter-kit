import { flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope } from 'vue';
import { useGlobalSearch } from '@/composables/useGlobalSearch';
import type { UseGlobalSearchReturn } from '@/composables/useGlobalSearch';
import { setUrlDefaults } from '@/wayfinder';

setUrlDefaults({ activeTeam: 'nubos' });

function answering(groups: unknown[]): void {
    vi.stubGlobal(
        'fetch',
        vi.fn(() =>
            Promise.resolve(
                new Response(JSON.stringify({ data: { groups } }), {
                    status: 200,
                    headers: { 'Content-Type': 'application/json' },
                }),
            ),
        ),
    );
}

async function searched(term: string): Promise<UseGlobalSearchReturn> {
    const scope = effectScope();
    const search = scope.run(() => useGlobalSearch()) as UseGlobalSearchReturn;

    search.search(term);
    await vi.advanceTimersByTimeAsync(500);
    await flushPromises();

    return search;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('useGlobalSearch — group types', () => {
    it.each([
        [
            'reduces a model class name to its lower-case base name',
            'App\\Models\\Company',
            'company',
        ],
        ['keeps a type that carries no namespace', 'deals', 'deals'],
    ])('%s', async (_case, raw, expected) => {
        answering([
            {
                type: raw,
                label: 'Firmen',
                records: [{ id: 'rec-1', title: 'Acme' }],
            },
        ]);

        const search = await searched('acme');

        expect(search.groups.value).toEqual([
            {
                type: expected,
                label: 'Firmen',
                records: [{ id: 'rec-1', title: 'Acme' }],
            },
        ]);
    });
});
