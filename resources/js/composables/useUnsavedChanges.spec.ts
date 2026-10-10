import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EffectScope } from 'vue';
import { effectScope, ref } from 'vue';
import { useUnsavedChanges } from '@/composables/useUnsavedChanges';

type GuardCallback = (event: GuardEvent) => boolean | void;

const dispose = vi.fn();
const on = vi.fn((event: string, callback: GuardCallback) => {
    void event;
    void callback;

    return dispose;
});
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        on: (event: string, callback: GuardCallback) => on(event, callback),
        visit: (...args: unknown[]) => visit(...args),
    },
}));

interface GuardEvent {
    detail: {
        visit: {
            method: string;
            url: URL;
            only: string[];
            except: string[];
            prefetch: boolean;
        };
    };
}

function guardEvent(
    url = 'https://app.test/engine/teams',
    method = 'get',
    only: string[] = [],
    prefetch = false,
): GuardEvent {
    return {
        detail: {
            visit: { method, url: new URL(url), only, except: [], prefetch },
        },
    };
}

function guardCallback(): GuardCallback {
    const registration = on.mock.calls.find((call) => call[0] === 'before');

    return registration![1];
}

const openScopes: EffectScope[] = [];

function setup(backHref = '/engine/teams') {
    const name = ref<string>('Sales');
    const scope = effectScope();

    openScopes.push(scope);
    const unsaved = scope.run(() =>
        useUnsavedChanges({
            values: () => ({ name: name.value }),
            backHref,
        }),
    )!;

    return { name, scope, ...unsaved };
}

describe('useUnsavedChanges', () => {
    beforeEach(() => {
        dispose.mockClear();
        on.mockClear();
        visit.mockClear();
    });

    afterEach(() => {
        openScopes.splice(0).forEach((scope) => scope.stop());
    });

    it('starts clean and turns dirty once a value changes', () => {
        const { name, isDirty } = setup();

        expect(isDirty.value).toBe(false);

        name.value = 'Sales North';

        expect(isDirty.value).toBe(true);
    });

    it('becomes clean again when the value returns to its baseline', () => {
        const { name, isDirty } = setup();

        name.value = 'Sales North';
        name.value = 'Sales';

        expect(isDirty.value).toBe(false);
    });

    it('accepts the current values as the new baseline after a save', () => {
        const { name, isDirty, markSaved } = setup();

        name.value = 'Sales North';
        markSaved();

        expect(isDirty.value).toBe(false);
    });

    it('leaves straight away when nothing was changed', () => {
        const { requestLeave, promptOpen } = setup();

        requestLeave();

        expect(promptOpen.value).toBe(false);
        expect(visit).toHaveBeenCalledWith('/engine/teams');
    });

    it('asks before leaving while changes are pending', () => {
        const { name, requestLeave, promptOpen } = setup();

        name.value = 'Sales North';
        requestLeave();

        expect(promptOpen.value).toBe(true);
        expect(visit).not.toHaveBeenCalled();
    });

    it('keeps the user on the page when the prompt is dismissed', () => {
        const { name, requestLeave, cancelLeave, promptOpen } = setup();

        name.value = 'Sales North';
        requestLeave();
        cancelLeave();

        expect(promptOpen.value).toBe(false);
        expect(visit).not.toHaveBeenCalled();
    });

    it('navigates back once the user confirms', () => {
        const { name, requestLeave, confirmLeave, promptOpen } = setup();

        name.value = 'Sales North';
        requestLeave();
        confirmLeave();

        expect(promptOpen.value).toBe(false);
        expect(visit).toHaveBeenCalledWith('/engine/teams', expect.anything());
    });

    it('cancels a navigation away while changes are pending', () => {
        const { name, promptOpen } = setup();
        const guard = guardCallback();

        expect(guard(guardEvent())).toBeUndefined();

        name.value = 'Sales North';

        expect(guard(guardEvent())).toBe(false);
        expect(promptOpen.value).toBe(true);
    });

    it('resumes the intercepted target instead of the back link', () => {
        const { name, confirmLeave } = setup();
        const guard = guardCallback();

        name.value = 'Sales North';
        guard(guardEvent('https://app.test/engine/roles'));
        confirmLeave();

        expect(visit).toHaveBeenCalledWith(
            'https://app.test/engine/roles',
            expect.anything(),
        );
    });

    it('lets form submissions and partial reloads through', () => {
        const { name } = setup();
        const guard = guardCallback();

        name.value = 'Sales North';

        expect(guard(guardEvent('https://app.test/engine/teams', 'put'))).toBe(
            undefined,
        );
        expect(
            guard(guardEvent('https://app.test/engine/teams', 'get', ['rows'])),
        ).toBe(undefined);
    });

    it('lets link prefetches through without prompting', () => {
        const { name, promptOpen } = setup();
        const guard = guardCallback();

        name.value = 'Sales North';

        expect(
            guard(guardEvent('https://app.test/engine/roles', 'get', [], true)),
        ).toBeUndefined();
        expect(promptOpen.value).toBe(false);
    });

    it('lets the confirmed navigation itself through the guard', () => {
        const { name, requestLeave, confirmLeave } = setup();
        const guard = guardCallback();

        name.value = 'Sales North';
        requestLeave();
        confirmLeave();

        expect(guard(guardEvent())).toBeUndefined();
    });

    it('takes its baseline once the initial render has settled', () => {
        const child = {
            name: 'ChildStub',
            emits: ['ready'],
            setup(_: unknown, { emit }: { emit: (event: string) => void }) {
                emit('ready');

                return () => null;
            },
        };

        const host = {
            components: { child },
            setup() {
                const value = ref<string>('');
                const { isDirty } = useUnsavedChanges({
                    values: () => ({ value: value.value }),
                    backHref: '/engine/teams',
                });

                return {
                    value,
                    isDirty,
                    onReady: () => (value.value = 'seed'),
                };
            },
            template: '<child @ready="onReady" />',
        };

        const wrapper = mount(host);

        expect(wrapper.vm.isDirty).toBe(false);

        wrapper.unmount();
    });

    it('warns before the browser unloads the page with pending changes', () => {
        const { name, scope } = setup();

        expect(
            window.dispatchEvent(
                new Event('beforeunload', { cancelable: true }),
            ),
        ).toBe(true);

        name.value = 'Sales North';

        expect(
            window.dispatchEvent(
                new Event('beforeunload', { cancelable: true }),
            ),
        ).toBe(false);

        scope.stop();

        expect(
            window.dispatchEvent(
                new Event('beforeunload', { cancelable: true }),
            ),
        ).toBe(true);
    });

    it('stops guarding when the owning scope is disposed', () => {
        const { scope } = setup();

        scope.stop();

        expect(dispose).toHaveBeenCalled();
    });
});
