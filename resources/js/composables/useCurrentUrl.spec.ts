import { usePage } from '@inertiajs/vue3';
import { describe, expect, it, vi } from 'vitest';
import { useCurrentUrl } from '@/composables/useCurrentUrl';

const pageState = vi.hoisted(() => ({ url: '/T1/crm/mailbox' }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: makeReactive } = await import('vue');
    const page = makeReactive(pageState);

    return { usePage: () => page };
});

describe('useCurrentUrl', () => {
    it('treats a trailing slash on the current page as the same page', () => {
        usePage().url = '/T1/crm/mailbox/';

        expect(useCurrentUrl().isCurrentUrl('/T1/crm/mailbox')).toBe(true);
    });

    it('treats a trailing slash on the link as the same page', () => {
        usePage().url = '/T1/crm/mailbox';

        expect(useCurrentUrl().isCurrentUrl('/T1/crm/mailbox/')).toBe(true);
    });

    it('keeps a sub-page apart from its parent', () => {
        usePage().url = '/T1/crm/mailbox/settings';

        expect(useCurrentUrl().isCurrentUrl('/T1/crm/mailbox')).toBe(false);
    });
});
