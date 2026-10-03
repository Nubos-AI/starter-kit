import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import FieldRecomputeAction from '@/components/engine/objectType/FieldRecomputeAction.vue';

function mountAction() {
    return mount(FieldRecomputeAction, {
        props: { objectTypeSlug: 'companies', fieldId: 'fd-brutto' },
    });
}

function respondWith(ok: boolean, body: unknown) {
    return vi.fn().mockResolvedValue({
        ok,
        json: () => Promise.resolve(body),
    });
}

beforeEach(() => {
    vi.stubGlobal('fetch', respondWith(true, { run: null }));
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('FieldRecomputeAction', () => {
    it('posts to the recompute endpoint and reports the record count', async () => {
        const request = respondWith(true, { run: { totalCount: 750000 } });
        vi.stubGlobal('fetch', request);

        const wrapper = mountAction();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(request).toHaveBeenCalledOnce();
        expect(request.mock.calls[0][1].method).toBe('POST');
        expect(wrapper.get('[role="status"]').text()).toContain('750.000');
    });

    it('reports a failed start instead of pretending it ran', async () => {
        vi.stubGlobal('fetch', respondWith(false, {}));

        const wrapper = mountAction();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="status"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('ließ sich nicht starten');
    });

    it('reports a failed start when the request itself throws', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

        const wrapper = mountAction();
        await wrapper.get('button').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('ließ sich nicht starten');
    });
});
